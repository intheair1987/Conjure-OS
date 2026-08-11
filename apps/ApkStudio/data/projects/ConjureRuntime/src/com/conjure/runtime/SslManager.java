package com.conjure.runtime;

import java.io.File;
import java.io.FileOutputStream;
import java.net.Inet4Address;
import java.net.InetAddress;
import java.net.NetworkInterface;
import java.util.ArrayList;
import java.util.Enumeration;
import java.util.HashSet;
import java.util.List;
import java.util.Set;

public final class SslManager {
    private final File runtimeRoot;
    private final String nativeLibDir;

    public SslManager(File runtimeRoot, String nativeLibDir) {
        this.runtimeRoot = runtimeRoot;
        this.nativeLibDir = nativeLibDir;
    }

    public File getSslDir() {
        File sslDir = new File(runtimeRoot, "ssl");
        if (!sslDir.exists()) {
            sslDir.mkdirs();
        }
        return sslDir;
    }

    public File getLogDir() {
        File logDir = new File(runtimeRoot, "logs");
        if (!logDir.exists()) {
            logDir.mkdirs();
        }
        return logDir;
    }

    public File getCaCertFile() {
        return new File(getSslDir(), "ca.crt");
    }

    public File getCaKeyFile() {
        return new File(getSslDir(), "ca.key");
    }

    public File getServerCertFile() {
        return new File(getSslDir(), "server.crt");
    }

    public File getServerKeyFile() {
        return new File(getSslDir(), "server.key");
    }

    public static List<String> getActiveIpv4Addresses() {
        List<String> addresses = new ArrayList<>();
        try {
            Enumeration<NetworkInterface> interfaces = NetworkInterface.getNetworkInterfaces();
            while (interfaces != null && interfaces.hasMoreElements()) {
                NetworkInterface iface = interfaces.nextElement();
                if (iface.isLoopback() || !iface.isUp()) {
                    continue;
                }
                Enumeration<InetAddress> inetAddresses = iface.getInetAddresses();
                while (inetAddresses.hasMoreElements()) {
                    InetAddress addr = inetAddresses.nextElement();
                    if (addr instanceof Inet4Address) {
                        String host = addr.getHostAddress();
                        if (host != null && !host.startsWith("127.")) {
                            addresses.add(host);
                        }
                    }
                }
            }
        } catch (Exception ignored) {
        }
        return addresses;
    }

    public Set<String> generateIpSanList() {
        Set<String> ipList = new HashSet<>();
        
        ipList.add("127.0.0.1");
        for (int i = 2; i <= 10; i++) {
            ipList.add("127.0.0." + i);
        }

        List<String> activeIps = getActiveIpv4Addresses();
        for (String ip : activeIps) {
            ipList.add(ip);
            int lastDot = ip.lastIndexOf('.');
            if (lastDot > 0) {
                String prefix = ip.substring(0, lastDot + 1);
                for (int i = 1; i <= 254; i++) {
                    ipList.add(prefix + i);
                }
            }
        }

        for (int i = 1; i <= 254; i++) {
            ipList.add("192.168.0." + i);
            ipList.add("192.168.1." + i);
            ipList.add("192.168.43." + i);
            ipList.add("10.0.0." + i);
        }

        return ipList;
    }

    public synchronized void ensureCertificates() throws Exception {
        File sslDir = getSslDir();
        File caKey = getCaKeyFile();
        File caCrt = getCaCertFile();
        File serverKey = getServerKeyFile();
        File serverCrt = getServerCertFile();
        File opensslBin = new File(nativeLibDir, "libopenssl.so");

        if (!opensslBin.exists()) {
            throw new Exception("libopenssl.so binary not found in " + nativeLibDir);
        }

        File cnfFile = new File(sslDir, "openssl.cnf");
        writeOpenSslConfig(cnfFile, generateIpSanList());

        File persistentBaseDir = new File(android.os.Environment.getExternalStorageDirectory(), "Conjure_Config/ssl");
        if (!persistentBaseDir.exists()) persistentBaseDir.mkdirs();

        File persistentCaDir = new File(persistentBaseDir, "root_ca");
        if (!persistentCaDir.exists()) persistentCaDir.mkdirs();

        File savedCaCrt = new File(persistentCaDir, "ca.crt");
        File savedCaKey = new File(persistentCaDir, "ca.key");

        // Migration check: Purge legacy Root CA if it uses generic CN=Conjure Local Root CA
        if (savedCaCrt.exists()) {
            try (java.io.BufferedReader br = new java.io.BufferedReader(new java.io.FileReader(savedCaCrt))) {
                StringBuilder sb = new StringBuilder();
                String line;
                while ((line = br.readLine()) != null) sb.append(line);
                if (sb.toString().contains("Conjure Local Root CA")) {
                    savedCaCrt.delete();
                    savedCaKey.delete();
                    caCrt.delete();
                    caKey.delete();
                }
            } catch (Exception ignored) {}
        }

        // 1. Restore master Root CA from persistent vault if local copy was wiped during reinstall
        if ((!caCrt.exists() || !caKey.exists() || caCrt.length() == 0 || caKey.length() == 0)
                && savedCaCrt.exists() && savedCaKey.exists() && savedCaCrt.length() > 0 && savedCaKey.length() > 0) {
            copyFile(savedCaCrt, caCrt);
            copyFile(savedCaKey, caKey);
        }

        // 2. Generate new master Root CA if missing from both local and persistent storage
        if (!caKey.exists() || !caCrt.exists() || caKey.length() == 0 || caCrt.length() == 0) {
            runOpenSsl(cnfFile, "req", "-x509", "-newkey", "rsa:2048", "-nodes",
                    "-keyout", caKey.getAbsolutePath(),
                    "-out", caCrt.getAbsolutePath(),
                    "-days", "3650",
                    "-subj", "/CN=Conjure Runtime Root CA/O=Conjure Runtime",
                    "-config", cnfFile.getAbsolutePath());

            if (caCrt.exists() && caKey.exists()) {
                copyFile(caCrt, savedCaCrt);
                copyFile(caKey, savedCaKey);
            }
        } else {
            if (!savedCaCrt.exists() || !savedCaKey.exists()) {
                copyFile(caCrt, savedCaCrt);
                copyFile(caKey, savedCaKey);
            }
        }

        File markerFile = new File(sslDir, "tailscale_cert.marker");
        File tsCert = new File(sslDir, "tailscale_server.crt");
        File tsKey = new File(sslDir, "tailscale_server.key");

        // Check if persistent Let's Encrypt certificates exist in domain vault /sdcard/Conjure_Config/ssl/
        File activeDomainFile = new File(persistentBaseDir, "active_domain.txt");
        if (activeDomainFile.exists() && activeDomainFile.length() > 0) {
            try (java.io.BufferedReader br = new java.io.BufferedReader(new java.io.FileReader(activeDomainFile))) {
                String domain = br.readLine();
                if (domain != null && !domain.trim().isEmpty()) {
                    File domainDir = new File(persistentBaseDir, "domains/" + domain.trim());
                    File savedCert = new File(domainDir, "tailscale_server.crt");
                    File savedKey = new File(domainDir, "tailscale_server.key");
                    File savedMarker = new File(domainDir, "tailscale_cert.marker");

                    if (savedCert.exists() && savedCert.length() > 0 && savedKey.exists() && savedKey.length() > 0 && savedMarker.exists()) {
                        copyFile(savedCert, tsCert);
                        copyFile(savedKey, tsKey);
                        copyFile(savedMarker, markerFile);
                    }
                }
            } catch (Exception ignored) {}
        }

        // Migration: If server.crt was previously overwritten with the Tailscale cert, move it to tailscale_server.crt
        if (markerFile.exists() && (!tsCert.exists() || tsCert.length() == 0)) {
            if (serverCrt.exists()) copyFile(serverCrt, tsCert);
            if (serverKey.exists()) copyFile(serverKey, tsKey);
            serverCrt.delete();
            serverKey.delete();
        }

        // Migration: Force regeneration of server.crt to enforce Apple iOS TLS EKU & 365-day validity rules
        File ekuMarker = new File(sslDir, "server_eku_v1.marker");
        if (!ekuMarker.exists()) {
            if (serverCrt.exists()) serverCrt.delete();
            if (serverKey.exists()) serverKey.delete();
            try { ekuMarker.createNewFile(); } catch (Exception ignored) {}
        }

        // Generate clean local server.crt / server.key for 127.0.0.1 if missing or migrated above
        if (!serverCrt.exists() || !serverKey.exists() || serverCrt.length() == 0 || serverKey.length() == 0) {
            File serverCsr = new File(sslDir, "server.csr");
            runOpenSsl(cnfFile, "req", "-newkey", "rsa:2048", "-nodes",
                    "-keyout", serverKey.getAbsolutePath(),
                    "-out", serverCsr.getAbsolutePath(),
                    "-config", cnfFile.getAbsolutePath());

            runOpenSsl(cnfFile, "x509", "-req",
                    "-in", serverCsr.getAbsolutePath(),
                    "-CA", caCrt.getAbsolutePath(),
                    "-CAkey", caKey.getAbsolutePath(),
                    "-CAcreateserial",
                    "-out", serverCrt.getAbsolutePath(),
                    "-days", "365",
                    "-extfile", cnfFile.getAbsolutePath(),
                    "-extensions", "v3_req");

            if (serverCsr.exists()) {
                serverCsr.delete();
            }
        }
    }

    private void writeOpenSslConfig(File cnfFile, Set<String> ipList) throws Exception {
        List<String> preservedDns = new ArrayList<>();
        if (cnfFile.exists() && cnfFile.isFile()) {
            try (java.io.BufferedReader reader = new java.io.BufferedReader(new java.io.FileReader(cnfFile))) {
                String line;
                while ((line = reader.readLine()) != null) {
                    String trimmed = line.trim();
                    if (trimmed.startsWith("DNS.10") || trimmed.startsWith("DNS.11") || trimmed.startsWith("DNS.12")) {
                        preservedDns.add(trimmed);
                    }
                }
            } catch (Exception ignored) {
            }
        }

        StringBuilder sb = new StringBuilder();
        sb.append("[req]\n");
        sb.append("distinguished_name = req_distinguished_name\n");
        sb.append("req_extensions = v3_req\n");
        sb.append("x509_extensions = v3_ca\n");
        sb.append("prompt = no\n\n");

        sb.append("[req_distinguished_name]\n");
        sb.append("CN = 127.0.0.1\n");
        sb.append("O = Conjure OS\n\n");

        sb.append("[v3_ca]\n");
        sb.append("basicConstraints = critical, CA:TRUE\n");
        sb.append("keyUsage = critical, digitalSignature, cRLSign, keyCertSign\n\n");

        sb.append("[v3_req]\n");
        sb.append("basicConstraints = CA:FALSE\n");
        sb.append("keyUsage = critical, digitalSignature, keyEncipherment\n");
        sb.append("extendedKeyUsage = serverAuth, clientAuth\n");
        sb.append("subjectAltName = @alt_names\n\n");

        sb.append("[alt_names]\n");
        sb.append("DNS.1 = localhost\n");
        sb.append("DNS.2 = conjure.local\n");
        sb.append("DNS.3 = *.conjure.local\n");
        sb.append("DNS.4 = *.ts.net\n");
        sb.append("DNS.5 = *.*.ts.net\n");
        sb.append("DNS.6 = *.*.*.ts.net\n");

        for (String customDns : preservedDns) {
            sb.append(customDns).append("\n");
        }

        int index = 1;
        for (String ip : ipList) {
            sb.append("IP.").append(index++).append(" = ").append(ip).append("\n");
        }

        try (FileOutputStream out = new FileOutputStream(cnfFile)) {
            out.write(sb.toString().getBytes("UTF-8"));
        }
    }

    private void copyFile(File src, File dest) {
        try {
            File parent = dest.getParentFile();
            if (parent != null && !parent.exists()) parent.mkdirs();
            try (java.io.FileInputStream in = new java.io.FileInputStream(src);
                 FileOutputStream out = new FileOutputStream(dest)) {
                byte[] buf = new byte[8192];
                int len;
                while ((len = in.read(buf)) > 0) {
                    out.write(buf, 0, len);
                }
                out.flush();
            }
        } catch (Exception ignored) {}
    }

    private void runOpenSsl(File cnfFile, String... args) throws Exception {
        File opensslBin = new File(nativeLibDir, "libopenssl.so");
        List<String> command = new ArrayList<>();
        command.add(opensslBin.getAbsolutePath());
        for (String arg : args) {
            command.add(arg);
        }

        ProcessBuilder pb = new ProcessBuilder(command);
        pb.directory(getSslDir());
        pb.environment().put("LD_LIBRARY_PATH", nativeLibDir);
        pb.environment().put("OPENSSL_CONF", cnfFile.getAbsolutePath());
        pb.redirectErrorStream(true);

        File sslLog = new File(getLogDir(), "ssl.log");
        pb.redirectOutput(ProcessBuilder.Redirect.appendTo(sslLog));

        Process p = pb.start();
        int exitCode = p.waitFor();
        if (exitCode != 0) {
            throw new Exception("OpenSSL command failed with exit code " + exitCode + ". See ssl.log.");
        }
    }
}
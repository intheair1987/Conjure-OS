package com.conjure.runtime;

import android.content.Context;
import android.net.wifi.WifiManager;
import java.io.BufferedReader;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.io.FileReader;
import java.net.DatagramPacket;
import java.net.DatagramSocket;
import java.net.Inet4Address;
import java.net.InetAddress;
import java.net.InetSocketAddress;
import java.net.MulticastSocket;
import java.net.NetworkInterface;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.Enumeration;
import java.util.List;
import java.util.Locale;

public final class MdnsManager {
    private final Context context;
    private File runtimeRoot;
    private WifiManager.MulticastLock multicastLock;
    private MulticastSocket socket;
    private Thread listenerThread;
    private volatile boolean isRunning = false;
    private String status = "STOPPED";
    private String statusMessage = "mDNS responder offline";
    private String activeIp = "";
    private int queriesHandled = 0;
    private boolean verified = false;

    private static final int MDNS_PORT = 5353;
    private static final String MDNS_GROUP = "224.0.0.251";
    private static final String TARGET_DOMAIN = "conjure.local";

    public MdnsManager(Context context) {
        this.context = context;
    }

    public void setRuntimeRoot(File runtimeRoot) {
        this.runtimeRoot = runtimeRoot;
    }

    public synchronized void start(File runtimeRoot) {
        this.runtimeRoot = runtimeRoot;
        start();
    }

    public synchronized void start() {
        if (isRunning) return;
        isRunning = true;
        status = "STARTING";
        statusMessage = "Initializing mDNS multicast listener...";
        queriesHandled = 0;
        verified = false;

        clearLog();
        appendLog("[start] Starting mDNS responder for " + TARGET_DOMAIN + " on UDP 5353...");

        acquireMulticastLock();

        listenerThread = new Thread(new Runnable() {
            @Override
            public void run() {
                runMdnsLoop();
            }
        }, "Conjure-mDNS-Loop");
        listenerThread.start();
    }

    public synchronized void stop() {
        isRunning = false;
        if (socket != null) {
            try {
                socket.close();
            } catch (Exception ignored) {}
            socket = null;
        }
        releaseMulticastLock();
        if (listenerThread != null) {
            listenerThread.interrupt();
            listenerThread = null;
        }
        status = "STOPPED";
        statusMessage = "mDNS responder stopped";
        appendLog("[stop] mDNS responder stopped.");
    }

    private void acquireMulticastLock() {
        if (context == null) {
            appendLog("[lock] Notice: Context is null. Skipping WifiManager.MulticastLock.");
            return;
        }
        try {
            WifiManager wifi = (WifiManager) context.getApplicationContext().getSystemService(Context.WIFI_SERVICE);
            if (wifi != null) {
                releaseMulticastLock();
                multicastLock = wifi.createMulticastLock("ConjureRuntimeMdns");
                multicastLock.setReferenceCounted(false);
                multicastLock.acquire();
                appendLog("[lock] Acquired WifiManager.MulticastLock successfully.");
            } else {
                appendLog("[lock] Warning: WifiManager instance unavailable.");
            }
        } catch (Exception e) {
            appendLog("[lock] Exception acquiring MulticastLock: " + e.getMessage());
        }
    }

    private void releaseMulticastLock() {
        if (multicastLock != null && multicastLock.isHeld()) {
            try {
                multicastLock.release();
                appendLog("[lock] Released WifiManager.MulticastLock.");
            } catch (Exception ignored) {}
        }
        multicastLock = null;
    }

    private static class InterfaceBinding {
        final NetworkInterface iface;
        final String ip;

        InterfaceBinding(NetworkInterface iface, String ip) {
            this.iface = iface;
            this.ip = ip;
        }
    }

    private List<InterfaceBinding> getAllActiveInterfaces() {
        List<InterfaceBinding> list = new ArrayList<>();
        try {
            Enumeration<NetworkInterface> interfaces = NetworkInterface.getNetworkInterfaces();
            while (interfaces != null && interfaces.hasMoreElements()) {
                NetworkInterface iface = interfaces.nextElement();
                if (iface.isLoopback() || !iface.isUp() || iface.isPointToPoint()) continue;
                Enumeration<InetAddress> addrs = iface.getInetAddresses();
                while (addrs.hasMoreElements()) {
                    InetAddress addr = addrs.nextElement();
                    if (addr instanceof Inet4Address && !addr.isLoopbackAddress()) {
                        String ip = addr.getHostAddress();
                        if (ip != null && !ip.startsWith("127.")) {
                            list.add(new InterfaceBinding(iface, ip));
                        }
                    }
                }
            }
        } catch (Exception ignored) {}
        return list;
    }

    private String buildInterfaceSignature(List<InterfaceBinding> bindings) {
        if (bindings == null || bindings.isEmpty()) return "";
        StringBuilder sb = new StringBuilder();
        for (InterfaceBinding b : bindings) {
            sb.append(b.iface.getName()).append("=").append(b.ip).append(";");
        }
        return sb.toString();
    }

    private InterfaceBinding resolveBestMatchingBinding(String senderIp, List<InterfaceBinding> bindings) {
        if (bindings == null || bindings.isEmpty()) return null;
        if (bindings.size() == 1) return bindings.get(0);

        if (senderIp != null && !senderIp.isEmpty()) {
            int lastDot = senderIp.lastIndexOf('.');
            if (lastDot > 0) {
                String subnetPrefix = senderIp.substring(0, lastDot + 1);
                for (InterfaceBinding b : bindings) {
                    if (b.ip.startsWith(subnetPrefix)) {
                        return b;
                    }
                }
                int secondDot = senderIp.indexOf('.', senderIp.indexOf('.') + 1);
                if (secondDot > 0) {
                    String prefix16 = senderIp.substring(0, secondDot + 1);
                    for (InterfaceBinding b : bindings) {
                        if (b.ip.startsWith(prefix16)) {
                            return b;
                        }
                    }
                }
            }
        }
        return bindings.get(0);
    }

    private void sendAnnouncements(List<InterfaceBinding> bindings) {
        if (socket == null || socket.isClosed() || bindings == null || bindings.isEmpty()) return;
        try {
            InetAddress groupAddr = InetAddress.getByName(MDNS_GROUP);
            for (InterfaceBinding binding : bindings) {
                byte[] responseBytes = buildMdnsResponse(new byte[] { 0, 0 }, binding.ip);
                if (responseBytes != null) {
                    DatagramPacket mcastPacket = new DatagramPacket(responseBytes, responseBytes.length, groupAddr, MDNS_PORT);
                    try {
                        socket.setNetworkInterface(binding.iface);
                        socket.send(mcastPacket);
                        appendLog("[announce] Broadcast gratuitous mDNS announcement for " + TARGET_DOMAIN + " (" + binding.ip + ") on " + binding.iface.getName());
                    } catch (Exception e) {
                        appendLog("[announce-err] Failed on " + binding.iface.getName() + ": " + e.getMessage());
                    }
                }
            }
        } catch (Exception e) {
            appendLog("[announce-err] General exception: " + e.getMessage());
        }
    }

    private void triggerAnnouncements(final List<InterfaceBinding> bindings) {
        new Thread(new Runnable() {
            @Override
            public void run() {
                sendAnnouncements(bindings);
                try { Thread.sleep(500); } catch (Exception ignored) {}
                sendAnnouncements(bindings);
            }
        }, "Conjure-mDNS-Announce").start();
    }

    private void runMdnsLoop() {
        byte[] buffer = new byte[1500];
        String lastIfaceSignature = "";
        long lastAnnouncementTime = 0;

        while (isRunning) {
            try {
                List<InterfaceBinding> bindings = getAllActiveInterfaces();
                if (bindings.isEmpty()) {
                    status = "NO_WIFI";
                    statusMessage = "No active network interfaces detected. Connect to Wi-Fi or Hotspot.";
                    if (!"NO_WIFI".equals(lastIfaceSignature)) {
                        appendLog("[net] Active network interface not detected. Retrying in 2s...");
                        lastIfaceSignature = "NO_WIFI";
                    }
                    if (socket != null && !socket.isClosed()) {
                        try { socket.close(); } catch (Exception ignored) {}
                        socket = null;
                    }
                    Thread.sleep(2000);
                    continue;
                }

                String currentSignature = buildInterfaceSignature(bindings);
                if (!currentSignature.equals(lastIfaceSignature)) {
                    appendLog("[net-change] Interface topology changed from '" + lastIfaceSignature + "' to '" + currentSignature + "'");
                    lastIfaceSignature = currentSignature;

                    if (socket != null && !socket.isClosed()) {
                        try { socket.close(); } catch (Exception ignored) {}
                        socket = null;
                    }
                    acquireMulticastLock();
                }

                StringBuilder ipListSb = new StringBuilder();
                for (int i = 0; i < bindings.size(); i++) {
                    if (i > 0) ipListSb.append(", ");
                    ipListSb.append(bindings.get(i).ip);
                }
                activeIp = ipListSb.toString();

                if (socket == null || socket.isClosed()) {
                    appendLog("[bind] Binding MulticastSocket to 0.0.0.0:5353 (SO_REUSEADDR)...");
                    socket = new MulticastSocket(null);
                    socket.setReuseAddress(true);
                    socket.bind(new InetSocketAddress(MDNS_PORT));

                    InetAddress group = InetAddress.getByName(MDNS_GROUP);
                    InetSocketAddress groupAddr = new InetSocketAddress(group, MDNS_PORT);

                    int joinedCount = 0;
                    for (InterfaceBinding binding : bindings) {
                        try {
                            socket.joinGroup(groupAddr, binding.iface);
                            joinedCount++;
                            appendLog("[bind] Joined multicast group " + MDNS_GROUP + ":5353 on " + binding.iface.getName() + " (" + binding.ip + ")");
                        } catch (Exception e) {
                            appendLog("[bind] Could not join group on " + binding.iface.getName() + ": " + e.getMessage());
                        }
                    }

                    if (joinedCount == 0) {
                        appendLog("[bind] Warning: Could not join multicast group on any interface. Retrying in 2s...");
                        try { socket.close(); } catch (Exception ignored) {}
                        socket = null;
                        Thread.sleep(2000);
                        continue;
                    }

                    socket.setSoTimeout(2000);

                    status = "RUNNING";
                    statusMessage = "Active on UDP 5353 (" + activeIp + ")";

                    triggerAnnouncements(bindings);
                    lastAnnouncementTime = System.currentTimeMillis();

                    sendSelfProbe(bindings.get(0).ip);
                }

                long now = System.currentTimeMillis();
                if (now - lastAnnouncementTime > 60000) {
                    lastAnnouncementTime = now;
                    triggerAnnouncements(bindings);
                }

                DatagramPacket packet = new DatagramPacket(buffer, buffer.length);
                try {
                    socket.receive(packet);
                } catch (java.net.SocketTimeoutException ste) {
                    continue;
                }

                int len = packet.getLength();
                if (len < 12) continue;

                int flags = ((buffer[2] & 0xFF) << 8) | (buffer[3] & 0xFF);
                boolean isQuery = (flags & 0x8000) == 0;
                if (!isQuery) continue;

                int qdCount = ((buffer[4] & 0xFF) << 8) | (buffer[5] & 0xFF);
                if (qdCount <= 0) continue;

                String queryName = parseDnsQueryName(buffer, 12);
                if (queryName == null) continue;

                if (queryName.equals(TARGET_DOMAIN) || queryName.endsWith("." + TARGET_DOMAIN) || queryName.contains("conjure")) {
                    queriesHandled++;
                    verified = true;
                    String senderAddr = packet.getAddress() != null ? packet.getAddress().getHostAddress() : "unknown";
                    appendLog("[query #" + queriesHandled + "] Received mDNS query for '" + queryName + "' from " + senderAddr);

                    InterfaceBinding targetBinding = resolveBestMatchingBinding(senderAddr, bindings);
                    String replyIp = targetBinding != null ? targetBinding.ip : bindings.get(0).ip;

                    byte[] txId = new byte[] { buffer[0], buffer[1] };
                    byte[] responseBytes = buildMdnsResponse(txId, replyIp);

                    if (responseBytes != null) {
                        InetAddress groupAddr = InetAddress.getByName(MDNS_GROUP);
                        DatagramPacket mcastPacket = new DatagramPacket(responseBytes, responseBytes.length, groupAddr, MDNS_PORT);

                        if (targetBinding != null) {
                            try {
                                socket.setNetworkInterface(targetBinding.iface);
                                socket.send(mcastPacket);
                            } catch (Exception e) {
                                socket.send(mcastPacket);
                            }
                        } else {
                            socket.send(mcastPacket);
                        }

                        try {
                            DatagramPacket ucastPacket = new DatagramPacket(responseBytes, responseBytes.length, packet.getSocketAddress());
                            socket.send(ucastPacket);
                        } catch (Exception ignored) {}

                        appendLog("  -> Transmitted A-record answer ('" + TARGET_DOMAIN + "' -> " + replyIp + ")");
                    }
                }
            } catch (Exception e) {
                if (!isRunning) break;
                status = "ERROR";
                statusMessage = "mDNS error: " + e.getMessage();
                appendLog("[error] mDNS loop exception: " + e.getMessage());
                if (socket != null && !socket.isClosed()) {
                    try { socket.close(); } catch (Exception ignored) {}
                }
                socket = null;
                try { Thread.sleep(2000); } catch (Exception ignored) {}
            }
        }
    }

    private void sendSelfProbe(final String currentIp) {
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    Thread.sleep(300);
                    appendLog("[probe] Executing self-probe query for " + TARGET_DOMAIN + "...");
                    ByteArrayOutputStream baos = new ByteArrayOutputStream();
                    baos.write(new byte[] { 0x12, 0x34 });
                    baos.write(new byte[] { 0x00, 0x00 });
                    baos.write(new byte[] { 0x00, 0x01 });
                    baos.write(new byte[] { 0x00, 0x00, 0x00, 0x00, 0x00, 0x00 });

                    baos.write(0x07);
                    baos.write("conjure".getBytes("UTF-8"));
                    baos.write(0x05);
                    baos.write("local".getBytes("UTF-8"));
                    baos.write(0x00);
                    baos.write(new byte[] { 0x00, 0x01 });
                    baos.write(new byte[] { 0x00, 0x01 });

                    byte[] queryBytes = baos.toByteArray();
                    DatagramPacket probePacket = new DatagramPacket(queryBytes, queryBytes.length, InetAddress.getByName(MDNS_GROUP), MDNS_PORT);

                    try (DatagramSocket probeSocket = new DatagramSocket()) {
                        probeSocket.send(probePacket);
                        appendLog("[probe] Self-probe query transmitted on UDP 5353.");
                    }
                } catch (Exception e) {
                    appendLog("[probe] Self-probe exception: " + e.getMessage());
                }
            }
        }).start();
    }

    private String parseDnsQueryName(byte[] data, int offset) {
        try {
            StringBuilder sb = new StringBuilder();
            int pos = offset;
            while (pos < data.length) {
                int len = data[pos] & 0xFF;
                if (len == 0) break;
                if ((len & 0xC0) == 0xC0) break;
                if (pos + 1 + len > data.length) break;
                if (sb.length() > 0) sb.append(".");
                sb.append(new String(data, pos + 1, len, java.nio.charset.StandardCharsets.UTF_8));
                pos += 1 + len;
            }
            return sb.toString().toLowerCase(Locale.US);
        } catch (Exception e) {
            return null;
        }
    }

    private byte[] buildMdnsResponse(byte[] txId, String ipAddress) {
        try {
            ByteArrayOutputStream baos = new ByteArrayOutputStream();

            baos.write(txId);
            baos.write(new byte[] { (byte) 0x84, 0x00 });
            baos.write(new byte[] { 0x00, 0x00 });
            baos.write(new byte[] { 0x00, 0x01 });
            baos.write(new byte[] { 0x00, 0x00 });
            baos.write(new byte[] { 0x00, 0x00 });

            baos.write(0x07);
            baos.write("conjure".getBytes("UTF-8"));
            baos.write(0x05);
            baos.write("local".getBytes("UTF-8"));
            baos.write(0x00);

            baos.write(new byte[] { 0x00, 0x01 });
            baos.write(new byte[] { (byte) 0x80, 0x01 });
            baos.write(new byte[] { 0x00, 0x00, 0x00, 0x78 });
            baos.write(new byte[] { 0x00, 0x04 });

            String[] parts = ipAddress.split("\\.");
            if (parts.length != 4) return null;
            for (String part : parts) {
                baos.write((byte) Integer.parseInt(part));
            }

            return baos.toByteArray();
        } catch (Exception e) {
            return null;
        }
    }

    public String getStatusJson() {
        return "{\"status\":\"" + escapeJson(status)
            + "\",\"message\":\"" + escapeJson(statusMessage)
            + "\",\"active_ip\":\"" + escapeJson(activeIp)
            + "\",\"queries_handled\":" + queriesHandled
            + ",\"verified\":" + verified + "}";
    }

    private void clearLog() {
        if (runtimeRoot == null) return;
        File logFile = new File(runtimeRoot, "logs/mdns.log");
        if (logFile.exists()) logFile.delete();
    }

    private void appendLog(String message) {
        if (runtimeRoot == null) return;
        try {
            File logDir = new File(runtimeRoot, "logs");
            if (!logDir.exists()) logDir.mkdirs();
            File logFile = new File(logDir, "mdns.log");

            SimpleDateFormat sdf = new SimpleDateFormat("yyyy/MM/dd HH:mm:ss", Locale.US);
            String timestamp = sdf.format(new Date());
            String line = timestamp + " " + message + "\n";

            try (FileOutputStream fos = new FileOutputStream(logFile, true)) {
                fos.write(line.getBytes("UTF-8"));
                fos.flush();
            }
        } catch (Exception ignored) {}
    }

    public String readLog() {
        if (runtimeRoot == null) return "mDNS log unavailable.";
        File logFile = new File(runtimeRoot, "logs/mdns.log");
        if (!logFile.exists()) return "No mDNS log found.";

        StringBuilder sb = new StringBuilder();
        try (BufferedReader reader = new BufferedReader(new FileReader(logFile))) {
            String line;
            while ((line = reader.readLine()) != null) {
                sb.append(line).append("\n");
            }
        } catch (Exception e) {
            return "Error reading mDNS log: " + e.getMessage();
        }
        return sb.toString();
    }

    private String escapeJson(String val) {
        if (val == null) return "";
        return val.replace("\\", "\\\\").replace("\"", "\\\"").replace("\r", "").replace("\n", "\\n");
    }
}
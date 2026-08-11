package com.conjure.buildkernel;

import java.io.*;
import java.util.*;

public class CompileEngine {

    public interface LogCallback {
        void onLog(String message);
    }

    private final File toolchainDir;
    private final File workspaceDir;
    private final String nativeLibDir;
    private final LogCallback logger;

    public CompileEngine(File toolchainDir, File workspaceDir, String nativeLibDir, LogCallback logger) {
        this.toolchainDir = toolchainDir;
        this.workspaceDir = workspaceDir;
        this.nativeLibDir = nativeLibDir;
        this.logger = logger;
    }

    public boolean compile(String projectName, File sourceZip, File outputApk) {
        try {
            log("Starting compilation for " + projectName + "...");
            File projDir = new File(workspaceDir, projectName);
            deleteDir(projDir);
            projDir.mkdirs();

            log("Extracting source code...");
            ZipUtility.unzip(sourceZip.getAbsolutePath(), projDir.getAbsolutePath());

            File buildDir = new File(projDir, "build");
            File genDir = new File(buildDir, "gen");
            File objDir = new File(buildDir, "obj");
            File apkDir = new File(buildDir, "apk");
            genDir.mkdirs();
            objDir.mkdirs();
            apkDir.mkdirs();

            // Toolchain references
            File aapt2 = new File(nativeLibDir, "libaapt2.so");
            if (!aapt2.exists()) {
                aapt2 = new File(toolchainDir, "aapt2");
            }
            File androidJar = new File(toolchainDir, "android.jar");
            File ecjDex = new File(toolchainDir, "ecj.jar");
            File d8Dex = new File(toolchainDir, "d8.jar");
            File signerDex = new File(toolchainDir, "apksigner.jar");
            File keystore = new File(toolchainDir, "debug.keystore");

            if (!aapt2.exists()) {
                throw new Exception("Toolchain missing: aapt2 binary not found at " + aapt2.getAbsolutePath());
            }

            // Android 14+ Security Mandate: Any dynamically loaded DEX/JAR file (including classpaths)
            // MUST be read-only, otherwise ART aborts with SIGABRT (134) to prevent runtime tampering.
            androidJar.setWritable(false, false);
            ecjDex.setWritable(false, false);
            d8Dex.setWritable(false, false);
            signerDex.setWritable(false, false);

            // 1. AAPT2 Compile
            log("1. Compiling resources (aapt2)...");
            File compiledRes = new File(buildDir, "compiled_res.zip");
            runCommand(projDir, aapt2.getAbsolutePath(), "compile", "--dir", "res/", "-o", compiledRes.getAbsolutePath());

            // 2. AAPT2 Link
            log("2. Linking resources (aapt2)...");
            File unsignedApk = new File(apkDir, "app-unsigned.apk");
            runCommand(projDir, aapt2.getAbsolutePath(), "link", compiledRes.getAbsolutePath(),
                    "-I", "/system/framework/framework-res.apk",
                    "--manifest", "AndroidManifest.xml",
                    "--java", genDir.getAbsolutePath(),
                    "-o", unsignedApk.getAbsolutePath());

            // 3. ECJ (Java Compile)
            log("3. Compiling Java sources (ecj)...");
            List<String> ecjArgs = new ArrayList<>(Arrays.asList(
                    "dalvikvm", "-Xnoimage-dex2oat", "-cp", ecjDex.getAbsolutePath(), "org.eclipse.jdt.internal.compiler.batch.Main",
                    "-proc:none",
                    "-d", objDir.getAbsolutePath(),
                    "-cp", androidJar.getAbsolutePath(),
                    "-1.8"
            ));
            ecjArgs.addAll(findFiles(genDir, ".java"));
            ecjArgs.addAll(findFiles(new File(projDir, "src"), ".java"));
            runCommand(projDir, ecjArgs.toArray(new String[0]));

            // 4. D8 (Dex)
            log("4. Translating to Dalvik bytecode (d8)...");
            List<String> d8Args = new ArrayList<>(Arrays.asList(
                    "dalvikvm", "-Xnoimage-dex2oat", "-cp", d8Dex.getAbsolutePath(), "com.android.tools.r8.D8",
                    "--lib", androidJar.getAbsolutePath(),
                    "--output", apkDir.getAbsolutePath()
            ));
            d8Args.addAll(findFiles(objDir, ".class"));
            runCommand(projDir, d8Args.toArray(new String[0]));

            // 5. Package APK
            log("5. Packaging APK...");
            File classesDex = new File(apkDir, "classes.dex");
            
            java.util.Map<File, String> filesToAdd = new java.util.HashMap<>();
            if (classesDex.exists()) {
                filesToAdd.put(classesDex, "classes.dex");
            }
            
            // Standard Project Packaging: Bundle custom assets (HTML/CSS/JS)
            File assetsDir = new File(projDir, "assets");
            if (assetsDir.exists() && assetsDir.isDirectory()) {
                log("Bundling custom assets (assets/)...");
                for (String filePath : findFiles(assetsDir, "")) {
                    File f = new File(filePath);
                    String relPath = filePath.substring(projDir.getAbsolutePath().length() + 1);
                    filesToAdd.put(f, relPath.replace(File.separatorChar, '/'));
                }
            }
            
            // Standard Project Packaging: Bundle native libraries
            File libDir = new File(projDir, "lib");
            if (libDir.exists() && libDir.isDirectory()) {
                log("Bundling native libraries (lib/)...");
                for (String filePath : findFiles(libDir, "")) {
                    File f = new File(filePath);
                    String relPath = filePath.substring(projDir.getAbsolutePath().length() + 1);
                    filesToAdd.put(f, relPath.replace(File.separatorChar, '/'));
                }
            }
            
            // Self-Hosting Loop: If we are compiling BuildKernel, we must extract and bundle
            // our own native libraries into the package so the compiled output is also fully functional.
            if ("BuildKernel".equals(projectName)) {
                File nativeLibFolder = new File(nativeLibDir);
                if (nativeLibFolder.exists() && nativeLibFolder.isDirectory()) {
                    String primaryCpuAbi = android.os.Build.SUPPORTED_ABIS[0];
                    File[] nativeLibs = nativeLibFolder.listFiles();
                    if (nativeLibs != null) {
                        log("Self-packaging: Bundling native libraries for " + primaryCpuAbi + "...");
                        for (File lib : nativeLibs) {
                            if (lib.isFile() && lib.getName().endsWith(".so")) {
                                filesToAdd.put(lib, "lib/" + primaryCpuAbi + "/" + lib.getName());
                            }
                        }
                    }
                }
                
                // ALSO package our toolchain files as assets so the child is fully portable on new installations!
                if (toolchainDir.exists() && toolchainDir.isDirectory()) {
                    File[] toolchainFiles = toolchainDir.listFiles();
                    if (toolchainFiles != null) {
                        log("Self-packaging: Bundling compiler toolchain as assets...");
                        for (File tool : toolchainFiles) {
                            if (tool.isFile() && !tool.getName().endsWith(".so")) {
                                filesToAdd.put(tool, "assets/" + tool.getName());
                            }
                        }
                    }
                }
            }
            
            ZipUtility.addFilesToZip(filesToAdd, unsignedApk);

            // 5.5 Zipalign APK
            log("5.5 Aligning APK (zipalign)...");
            File alignedApk = new File(apkDir, "app-aligned.apk");
            File zipalign = new File(nativeLibDir, "libzipalign.so");
            if (!zipalign.exists()) {
                throw new Exception("Toolchain missing: zipalign binary not found at " + zipalign.getAbsolutePath());
            }
            runCommand(projDir, zipalign.getAbsolutePath(), "-f", "-p", "4", unsignedApk.getAbsolutePath(), alignedApk.getAbsolutePath());

            // 6. Sign APK
            log("6. Signing APK (apksigner)...");
            File customKeystore = new File(projDir, "custom.keystore");
            File signingConf = new File(projDir, "signing.conf");
            
            String ksPath;
            String ksType = "PKCS12";
            String ksPass = "android";
            String keyPass = "android";
            String keyAlias = "androiddebugkey";
            
            boolean useCustom = customKeystore.exists() && signingConf.exists();
            if (useCustom) {
                try {
                    Map<String, String> conf = parseSigningConf(signingConf);
                    if ("debug".equals(conf.get("SIGNING_MODE"))) {
                        useCustom = false;
                    }
                } catch (Exception e) {
                    // Ignore parsing failure, fallback safely
                }
            }
            
            if (useCustom) {
                log("Using custom uploaded keystore for signing...");
                try {
                    Map<String, String> conf = parseSigningConf(signingConf);
                    ksPath = customKeystore.getAbsolutePath();
                    ksPass = conf.containsKey("KS_PASS") ? conf.get("KS_PASS") : "android";
                    keyAlias = conf.containsKey("KEY_ALIAS") ? conf.get("KEY_ALIAS") : "androiddebugkey";
                    keyPass = conf.containsKey("KEY_PASS") ? conf.get("KEY_PASS") : ksPass;
                } catch (Exception e) {
                    log("⚠️ Error parsing signing.conf, falling back to default debug key.");
                    ksPath = keystore.getAbsolutePath();
                }
            } else {
                log("Using default debug keystore...");
                ksPath = keystore.getAbsolutePath();
                
                File publicBackup = new File("/sdcard/Download/" + projectName + "-debug.keystore");
                
                // Restore backup if present in Downloads but missing in private storage
                if (publicBackup.exists() && !keystore.exists()) {
                    log("Restoring stable debug keystore from Downloads backup...");
                    copyFile(publicBackup, keystore);
                }
                
                if (!keystore.exists()) {
                    throw new Exception("Toolchain missing: debug.keystore not found.");
                }
            }
            
            runCommand(projDir, "dalvikvm", "-Xnoimage-dex2oat", "-cp", signerDex.getAbsolutePath(), "com.android.apksigner.ApkSignerTool",
                    "sign", "--ks", ksPath,
                    "--ks-type", ksType,
                    "--ks-pass", "pass:" + ksPass,
                    "--key-pass", "pass:" + keyPass,
                    "--ks-key-alias", keyAlias,
                    "--out", outputApk.getAbsolutePath(),
                    alignedApk.getAbsolutePath());
                    
            // Backup the default debug keystore alongside the output APK
            if (!customKeystore.exists()) {
                File publicBackup = new File("/sdcard/Download/" + projectName + "-debug.keystore");
                if (!publicBackup.exists() && keystore.exists()) {
                    log("Backing up stable debug keystore to Downloads folder...");
                    copyFile(keystore, publicBackup);
                }
            }

            log("==================================================");
            log("✨ Build Complete: " + outputApk.getName());
            log("==================================================");
            return true;

        } catch (Exception e) {
            log("❌ Build Failed: " + e.getMessage());
            e.printStackTrace();
            return false;
        }
    }

    private Map<String, String> parseSigningConf(File confFile) throws IOException {
        Map<String, String> config = new HashMap<>();
        try (BufferedReader reader = new BufferedReader(new FileReader(confFile))) {
            String line;
            while ((line = reader.readLine()) != null) {
                line = line.trim();
                if (line.isEmpty() || line.startsWith("#")) continue;
                String[] parts = line.split("=", 2);
                if (parts.length == 2) {
                    String key = parts[0].trim();
                    String val = parts[1].trim();
                    if (val.startsWith("\"") && val.endsWith("\"")) {
                        val = val.substring(1, val.length() - 1);
                    } else if (val.startsWith("'") && val.endsWith("'")) {
                        val = val.substring(1, val.length() - 1);
                    }
                    config.put(key, val);
                }
            }
        }
        return config;
    }

    private void copyFile(File src, File dest) throws IOException {
        try (InputStream in = new FileInputStream(src);
             OutputStream out = new FileOutputStream(dest)) {
            byte[] buf = new byte[8192];
            int len;
            while ((len = in.read(buf)) > 0) {
                out.write(buf, 0, len);
            }
        }
    }

    private void runCommand(File workingDir, String... command) throws Exception {
        ProcessBuilder pb = new ProcessBuilder(command);
        pb.directory(workingDir);
        
        Map<String, String> env = pb.environment();
        String existingLd = env.get("LD_LIBRARY_PATH");
        if (existingLd == null || existingLd.isEmpty()) {
            env.put("LD_LIBRARY_PATH", nativeLibDir);
        } else {
            env.put("LD_LIBRARY_PATH", nativeLibDir + ":" + existingLd);
        }
        
        // Provide a writable ANDROID_DATA to prevent dalvikvm from aborting (Exit 134)
        File androidData = new File(workspaceDir, "android_data");
        File dalvikCache = new File(androidData, "dalvik-cache");
        dalvikCache.mkdirs();
        
        // ART requires architecture-specific subdirectories to exist, or it will abort (Exit 134)
        new File(dalvikCache, "arm").mkdirs();
        new File(dalvikCache, "arm64").mkdirs();
        new File(dalvikCache, "x86").mkdirs();
        new File(dalvikCache, "x86_64").mkdirs();
        
        env.put("ANDROID_DATA", androidData.getAbsolutePath());
        
        pb.redirectErrorStream(true);
        Process process = pb.start();

        try (BufferedReader reader = new BufferedReader(new InputStreamReader(process.getInputStream()))) {
            String line;
            while ((line = reader.readLine()) != null) {
                log(line);
            }
        }
        int exitCode = process.waitFor();
        if (exitCode != 0) {
            throw new Exception("Command failed with exit code " + exitCode);
        }
    }

    private List<String> findFiles(File dir, String extension) {
        List<String> files = new ArrayList<>();
        if (!dir.exists()) return files;
        File[] children = dir.listFiles();
        if (children != null) {
            for (File f : children) {
                if (f.isDirectory()) {
                    files.addAll(findFiles(f, extension));
                } else if (f.getName().endsWith(extension)) {
                    files.add(f.getAbsolutePath());
                }
            }
        }
        return files;
    }

    private void log(String msg) {
        if (logger != null) {
            logger.onLog(msg);
        }
    }

    private void deleteDir(File dir) {
        if (dir.isDirectory()) {
            File[] children = dir.listFiles();
            if (children != null) {
                for (File f : children) {
                    deleteDir(f);
                }
            }
        }
        dir.delete();
    }
}
package com.conjure.apkwrapper;

import android.content.Context;
import android.util.Base64;
import java.io.*;
import java.util.*;

public class CompileEngine {
    public interface LogCallback { void onLog(String message); }
    
    private final Context context;
    private final File toolchainDir;
    private final File workspaceDir;
    private final String nativeLibDir;
    private final LogCallback logger;

    public CompileEngine(Context context, File toolchainDir, File workspaceDir, String nativeLibDir, LogCallback logger) {
        this.context = context;
        this.toolchainDir = toolchainDir;
        this.workspaceDir = workspaceDir;
        this.nativeLibDir = nativeLibDir;
        this.logger = logger;
    }

    public boolean compile(String appName, String pkgName, String targetUrl, String base64Icon, File outputApk) {
        try {
            log("Starting compilation for " + appName + "...");
            File projDir = new File(workspaceDir, appName.replace(" ", ""));
            deleteDir(projDir);
            projDir.mkdirs();

            File buildDir = new File(projDir, "build");
            File genDir = new File(buildDir, "gen");
            File objDir = new File(buildDir, "obj");
            File apkDir = new File(buildDir, "apk");
            genDir.mkdirs(); objDir.mkdirs(); apkDir.mkdirs();

            log("Hydrating templates...");
            File srcDir = new File(projDir, "src/" + pkgName.replace('.', '/'));
            srcDir.mkdirs();
            File resValuesDir = new File(projDir, "res/values");
            resValuesDir.mkdirs();
            
            String manifestStr = readAsset("templates/AndroidManifest_child.xml.tpl");
            manifestStr = manifestStr.replace("{{APP_NAME}}", appName).replace("{{PACKAGE_NAME}}", pkgName);
            
            if (base64Icon != null && !base64Icon.isEmpty()) {
                File resDrawableDir = new File(projDir, "res/drawable");
                resDrawableDir.mkdirs();
                byte[] iconBytes = Base64.decode(base64Icon, Base64.DEFAULT);
                try (FileOutputStream fos = new FileOutputStream(new File(resDrawableDir, "app_icon.png"))) {
                    fos.write(iconBytes);
                }
            } else {
                manifestStr = manifestStr.replace("android:icon=\"@drawable/app_icon\"", "");
            }
            writeFile(new File(projDir, "AndroidManifest.xml"), manifestStr);

            String mainActStr = readAsset("templates/MainActivity_child.java.tpl");
            mainActStr = mainActStr.replace("{{APP_NAME}}", appName).replace("{{PACKAGE_NAME}}", pkgName).replace("{{WRAPPER_URL}}", targetUrl);
            writeFile(new File(srcDir, "MainActivity.java"), mainActStr);

            String stringsStr = readAsset("templates/strings_child.xml.tpl");
            stringsStr = stringsStr.replace("{{APP_NAME}}", appName).replace("{{PACKAGE_NAME}}", pkgName);
            writeFile(new File(resValuesDir, "strings.xml"), stringsStr);

            File assetsDir = new File(projDir, "assets");
            assetsDir.mkdirs();
            String settingsStr = readAsset("templates/settings_child.html.tpl");
            settingsStr = settingsStr.replace("{{APP_NAME}}", appName)
                                     .replace("{{PACKAGE_NAME}}", pkgName)
                                     .replace("{{WRAPPER_URL}}", targetUrl);
            writeFile(new File(assetsDir, "settings.html"), settingsStr);

            File aapt2 = new File(nativeLibDir, "libaapt2.so");
            File androidJar = new File(toolchainDir, "android.jar");
            File ecjDex = new File(toolchainDir, "ecj.jar");
            File d8Dex = new File(toolchainDir, "d8.jar");
            File signerDex = new File(toolchainDir, "apksigner.jar");
            File keystore = new File(toolchainDir, "debug.keystore");

            log("1. Compiling resources (aapt2)...");
            File compiledRes = new File(buildDir, "compiled_res.zip");
            runCommand(projDir, aapt2.getAbsolutePath(), "compile", "--dir", "res/", "-o", compiledRes.getAbsolutePath());

            log("2. Linking resources (aapt2)...");
            File unsignedApk = new File(apkDir, "app-unsigned.apk");
            runCommand(projDir, aapt2.getAbsolutePath(), "link", compiledRes.getAbsolutePath(),
                    "-I", "/system/framework/framework-res.apk",
                    "--manifest", "AndroidManifest.xml",
                    "--java", genDir.getAbsolutePath(),
                    "-o", unsignedApk.getAbsolutePath());

            log("3. Compiling Java sources (ecj)...");
            List<String> ecjArgs = new ArrayList<>(Arrays.asList(
                    "dalvikvm", "-Xnoimage-dex2oat", "-cp", ecjDex.getAbsolutePath(), "org.eclipse.jdt.internal.compiler.batch.Main",
                    "-proc:none", "-d", objDir.getAbsolutePath(), "-cp", androidJar.getAbsolutePath(), "-1.8"
            ));
            ecjArgs.addAll(findFiles(genDir, ".java"));
            ecjArgs.addAll(findFiles(new File(projDir, "src"), ".java"));
            runCommand(projDir, ecjArgs.toArray(new String[0]));

            log("4. Translating to Dalvik bytecode (d8)...");
            List<String> d8Args = new ArrayList<>(Arrays.asList(
                    "dalvikvm", "-Xnoimage-dex2oat", "-cp", d8Dex.getAbsolutePath(), "com.android.tools.r8.D8",
                    "--lib", androidJar.getAbsolutePath(), "--output", apkDir.getAbsolutePath()
            ));
            d8Args.addAll(findFiles(objDir, ".class"));
            runCommand(projDir, d8Args.toArray(new String[0]));

            log("5. Packaging APK...");
            File classesDex = new File(apkDir, "classes.dex");
            Map<File, String> filesToAdd = new HashMap<>();
            if (classesDex.exists()) filesToAdd.put(classesDex, "classes.dex");
            if (assetsDir.exists() && assetsDir.isDirectory()) {
                addDirToZipMap(assetsDir, "assets", filesToAdd);
            }
            ZipUtility.addFilesToZip(filesToAdd, unsignedApk);

            log("5.5 Aligning APK (zipalign)...");
            File alignedApk = new File(apkDir, "app-aligned.apk");
            File zipalign = new File(nativeLibDir, "libzipalign.so");
            runCommand(projDir, zipalign.getAbsolutePath(), "-f", "-p", "4", unsignedApk.getAbsolutePath(), alignedApk.getAbsolutePath());

            log("6. Signing APK (apksigner)...");
            runCommand(projDir, "dalvikvm", "-Xnoimage-dex2oat", "-cp", signerDex.getAbsolutePath(), "com.android.apksigner.ApkSignerTool",
                    "sign", "--ks", keystore.getAbsolutePath(),
                    "--ks-type", "PKCS12",
                    "--ks-pass", "pass:android",
                    "--key-pass", "pass:android",
                    "--ks-key-alias", "androiddebugkey",
                    "--out", outputApk.getAbsolutePath(),
                    alignedApk.getAbsolutePath());

            log("==================================================");
            log("✨ Build Complete: " + outputApk.getAbsolutePath());
            log("==================================================");
            return true;
        } catch (Exception e) {
            log("❌ Build Failed: " + e.getMessage());
            e.printStackTrace();
            return false;
        }
    }

    private String readAsset(String path) throws IOException {
        try (InputStream is = context.getAssets().open(path);
             ByteArrayOutputStream baos = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[1024];
            int len;
            while ((len = is.read(buffer)) != -1) baos.write(buffer, 0, len);
            return baos.toString("UTF-8");
        }
    }

    private void writeFile(File file, String content) throws IOException {
        try (FileOutputStream fos = new FileOutputStream(file)) {
            fos.write(content.getBytes("UTF-8"));
        }
    }

    private void runCommand(File workingDir, String... command) throws Exception {
        ProcessBuilder pb = new ProcessBuilder(command);
        pb.directory(workingDir);
        Map<String, String> env = pb.environment();
        String existingLd = env.get("LD_LIBRARY_PATH");
        env.put("LD_LIBRARY_PATH", existingLd == null || existingLd.isEmpty() ? nativeLibDir : nativeLibDir + ":" + existingLd);
        
        File androidData = new File(workspaceDir, "android_data");
        File dalvikCache = new File(androidData, "dalvik-cache");
        dalvikCache.mkdirs();
        new File(dalvikCache, "arm").mkdirs();
        new File(dalvikCache, "arm64").mkdirs();
        new File(dalvikCache, "x86").mkdirs();
        new File(dalvikCache, "x86_64").mkdirs();
        env.put("ANDROID_DATA", androidData.getAbsolutePath());
        
        pb.redirectErrorStream(true);
        Process process = pb.start();
        try (BufferedReader reader = new BufferedReader(new InputStreamReader(process.getInputStream()))) {
            String line;
            while ((line = reader.readLine()) != null) log(line);
        }
        int exitCode = process.waitFor();
        if (exitCode != 0) throw new Exception("Command failed with exit code " + exitCode);
    }

    private void addDirToZipMap(File dir, String zipPrefix, Map<File, String> filesToAdd) {
        if (!dir.exists() || !dir.isDirectory()) return;
        File[] children = dir.listFiles();
        if (children != null) {
            for (File child : children) {
                String entryPath = zipPrefix + "/" + child.getName();
                if (child.isDirectory()) {
                    addDirToZipMap(child, entryPath, filesToAdd);
                } else if (child.isFile()) {
                    filesToAdd.put(child, entryPath);
                }
            }
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

    private void deleteDir(File dir) {
        if (dir.isDirectory()) {
            File[] children = dir.listFiles();
            if (children != null) {
                for (File f : children) deleteDir(f);
            }
        }
        dir.delete();
    }

    private void log(String msg) {
        if (logger != null) logger.onLog(msg);
    }
}
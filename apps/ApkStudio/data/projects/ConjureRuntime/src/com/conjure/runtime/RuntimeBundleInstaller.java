package com.conjure.runtime;

import android.content.Context;
import android.content.res.AssetManager;
import android.os.Build;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;

public final class RuntimeBundleInstaller {
    private RuntimeBundleInstaller() {
    }

    public static void install(Context context, File runtimeRoot) {
        String abi = resolveAbi();
        AssetManager assets = context.getAssets();

        installAsset(assets, runtimeRoot, "binaries/" + abi + "/php");
        installAsset(assets, runtimeRoot, "binaries/" + abi + "/nginx");

        try {
            String[] libraries = assets.list("binaries/" + abi + "/lib");
            if (libraries != null) {
                for (String library : libraries) {
                    installAsset(
                        assets,
                        new File(runtimeRoot, "lib"),
                        "binaries/" + abi + "/lib/" + library
                    );
                }
            }
        } catch (Exception ignored) {
        }
    }

    private static void installAsset(AssetManager assets, File runtimeRoot, String assetPath) {
        String fileName = assetPath.substring(assetPath.lastIndexOf('/') + 1);
        File destination = new File(new File(runtimeRoot, "bin"), fileName);

        if (destination.isFile() && destination.length() > 0) {
            destination.setExecutable(true, true);
            return;
        }

        File parent = destination.getParentFile();
        if (parent != null && !parent.exists()) {
            parent.mkdirs();
        }

        try (InputStream input = assets.open(assetPath);
             FileOutputStream output = new FileOutputStream(destination)) {
            byte[] buffer = new byte[8192];
            int count;

            while ((count = input.read(buffer)) != -1) {
                output.write(buffer, 0, count);
            }

            output.flush();
            destination.setReadable(true, true);
            destination.setExecutable(true, true);
        } catch (Exception ignored) {
            if (destination.exists() && destination.length() == 0) {
                destination.delete();
            }
        }
    }

    private static String resolveAbi() {
        if (Build.VERSION.SDK_INT >= 21 && Build.SUPPORTED_ABIS != null && Build.SUPPORTED_ABIS.length > 0) {
            return Build.SUPPORTED_ABIS[0];
        }

        return "arm64-v8a";
    }
}
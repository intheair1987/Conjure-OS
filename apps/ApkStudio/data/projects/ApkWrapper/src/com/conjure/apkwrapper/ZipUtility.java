package com.conjure.apkwrapper;

import java.io.*;
import java.util.Map;
import java.util.zip.*;

public class ZipUtility {
    public static void addFilesToZip(Map<File, String> filesToAdd, File zipFile) throws IOException {
        File tempFile = File.createTempFile("temp", ".zip", zipFile.getParentFile());
        try (ZipInputStream zin = new ZipInputStream(new FileInputStream(zipFile));
             ZipOutputStream zout = new ZipOutputStream(new FileOutputStream(tempFile))) {
            
            ZipEntry entry = zin.getNextEntry();
            while (entry != null) {
                boolean shouldOverwrite = false;
                for (String entryName : filesToAdd.values()) {
                    if (entry.getName().equals(entryName)) {
                        shouldOverwrite = true;
                        break;
                    }
                }
                if (!shouldOverwrite) {
                    ZipEntry newEntry = new ZipEntry(entry);
                    zout.putNextEntry(newEntry);
                    copyStream(zin, zout);
                    zout.closeEntry();
                }
                entry = zin.getNextEntry();
            }
            
            for (Map.Entry<File, String> mapEntry : filesToAdd.entrySet()) {
                File file = mapEntry.getKey();
                String entryName = mapEntry.getValue();
                ZipEntry newEntry = new ZipEntry(entryName);
                newEntry.setMethod(ZipEntry.DEFLATED);
                zout.putNextEntry(newEntry);
                try (FileInputStream fis = new FileInputStream(file)) {
                    copyStream(fis, zout);
                }
                zout.closeEntry();
            }
        }
        if (zipFile.delete()) {
            tempFile.renameTo(zipFile);
        } else {
            throw new IOException("Failed to replace original zip file during packaging.");
        }
    }

    private static void copyStream(InputStream in, OutputStream out) throws IOException {
        byte[] buffer = new byte[8192];
        int len;
        while ((len = in.read(buffer)) > 0) {
            out.write(buffer, 0, len);
        }
    }
}
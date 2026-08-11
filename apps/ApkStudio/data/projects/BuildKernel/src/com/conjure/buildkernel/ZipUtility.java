package com.conjure.buildkernel;

import java.io.*;
import java.util.zip.*;

public class ZipUtility {

    public static void unzip(String zipFilePath, String destDirectory) throws IOException {
        File destDir = new File(destDirectory);
        if (!destDir.exists()) {
            destDir.mkdirs();
        }
        try (ZipInputStream zipIn = new ZipInputStream(new FileInputStream(zipFilePath))) {
            ZipEntry entry = zipIn.getNextEntry();
            while (entry != null) {
                String filePath = destDirectory + File.separator + entry.getName();
                if (!entry.isDirectory()) {
                    extractFile(zipIn, filePath);
                } else {
                    File dir = new File(filePath);
                    dir.mkdirs();
                }
                zipIn.closeEntry();
                entry = zipIn.getNextEntry();
            }
        }
    }

    private static void extractFile(ZipInputStream zipIn, String filePath) throws IOException {
        File file = new File(filePath);
        file.getParentFile().mkdirs();
        try (BufferedOutputStream bos = new BufferedOutputStream(new FileOutputStream(file))) {
            byte[] bytesIn = new byte[4096];
            int read;
            while ((read = zipIn.read(bytesIn)) != -1) {
                bos.write(bytesIn, 0, read);
            }
        }
    }

    public static void addFileToZip(File sourceFile, File zipFile, String entryName) throws IOException {
        java.util.Map<File, String> map = new java.util.HashMap<>();
        map.put(sourceFile, entryName);
        addFilesToZip(map, zipFile);
    }

    public static void addFilesToZip(java.util.Map<File, String> filesToAdd, File zipFile) throws IOException {
        File tempFile = File.createTempFile("temp", ".zip", zipFile.getParentFile());
        try (ZipInputStream zin = new ZipInputStream(new FileInputStream(zipFile));
             ZipOutputStream zout = new ZipOutputStream(new FileOutputStream(tempFile))) {
            
            // 1. Copy all original entries, EXCEPT those we want to overwrite
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
            
            // 2. Add all new files (classes.dex, shared libraries)
            for (java.util.Map.Entry<File, String> mapEntry : filesToAdd.entrySet()) {
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
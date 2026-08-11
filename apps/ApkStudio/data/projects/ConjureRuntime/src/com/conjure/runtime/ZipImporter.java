package com.conjure.runtime;

import java.io.BufferedInputStream;
import java.io.BufferedOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.util.zip.ZipEntry;
import java.util.zip.ZipInputStream;

public final class ZipImporter {
    private static final int MAX_ZIP_ENTRIES = 100000;
    private static final long MAX_EXTRACTED_BYTES = 2L * 1024L * 1024L * 1024L;

    private ZipImporter() {
    }

    public interface ProgressListener {
        void onProgress(String stage, String detail, int percent);
    }

    public static File importZip(InputStream source, File stagingRoot, File finalRoot) throws IOException {
        return importZip(source, stagingRoot, finalRoot, null);
    }

    public static File importZip(InputStream source, File stagingRoot, File finalRoot, ProgressListener listener) throws IOException {
        if (listener != null) listener.onProgress("Extracting Package", "Unzipping files into staging storage...", 25);
        deleteRecursively(stagingRoot);
        if (!stagingRoot.mkdirs() && !stagingRoot.isDirectory()) {
            throw new IOException("Unable to create staging directory");
        }

        extractSafely(source, stagingRoot);

        if (listener != null) listener.onProgress("Validating Package", "Checking index.php and runtime directories...", 55);
        File[] extracted = stagingRoot.listFiles();
        if (extracted == null || extracted.length == 0) {
            throw new IOException("The selected ZIP is empty");
        }

        File packageRoot = resolvePackageRoot(stagingRoot);
        validateConjurePackage(packageRoot);

        File promotionRoot = new File(finalRoot.getParentFile(), finalRoot.getName() + ".incoming");
        File backupRoot = new File(finalRoot.getParentFile(), finalRoot.getName() + ".previous");

        deleteRecursively(promotionRoot);
        deleteRecursively(backupRoot);

        if (!promotionRoot.mkdirs() && !promotionRoot.isDirectory()) {
            throw new IOException("Unable to create incoming Conjure OS directory");
        }

        File[] stagedItems = packageRoot.listFiles();
        if (stagedItems == null) {
            throw new IOException("Unable to read staged package");
        }

        for (File item : stagedItems) {
            File destination = new File(promotionRoot, item.getName());
            if (!item.renameTo(destination)) {
                copyRecursively(item, destination);
            }

            if (!destination.exists()) {
                deleteRecursively(promotionRoot);
                throw new IOException("Unable to stage package item: " + item.getName());
            }
        }

        if (listener != null) listener.onProgress("Clean Overwrite", "Deleting old /sdcard/Conjure OS/ folder...", 80);
        if (finalRoot.exists()) {
            deleteRecursively(finalRoot);
        }

        if (listener != null) listener.onProgress("Activating Release", "Promoting fresh Conjure OS installation...", 95);
        if (!promotionRoot.renameTo(finalRoot)) {
            deleteRecursively(promotionRoot);
            throw new IOException("Unable to activate the new Conjure OS installation");
        }

        deleteRecursively(backupRoot);
        deleteRecursively(stagingRoot);
        return finalRoot;
    }

    private static File resolvePackageRoot(File stagingRoot) throws IOException {
        File directIndex = new File(stagingRoot, "index.php");
        if (directIndex.isFile()) {
            return stagingRoot;
        }

        File[] children = stagingRoot.listFiles();
        if (children != null) {
            File onlyDirectory = null;
            int directoryCount = 0;
            for (File child : children) {
                if (child.isDirectory()) {
                    onlyDirectory = child;
                    directoryCount++;
                } else if (child.isFile()) {
                    throw new IOException("Invalid package: root files are missing index.php");
                }
            }

            if (directoryCount == 1 && onlyDirectory != null) {
                return onlyDirectory;
            }
        }

        throw new IOException("Invalid package: unable to locate the Conjure OS package root");
    }

    private static void validateConjurePackage(File stagingRoot) throws IOException {
        File indexFile = new File(stagingRoot, "index.php");
        File appDirectory = new File(stagingRoot, "app");
        File appsDirectory = new File(stagingRoot, "apps");

        if (!indexFile.isFile()) {
            throw new IOException("Invalid package: root index.php was not found");
        }

        if (!appDirectory.isDirectory() && !appsDirectory.isDirectory()) {
            throw new IOException("Invalid package: expected Conjure OS runtime directories were not found");
        }

        if (indexFile.length() == 0) {
            throw new IOException("Invalid package: root index.php is empty");
        }
    }

    private static void extractSafely(InputStream source, File destinationRoot) throws IOException {
        String rootPath = destinationRoot.getCanonicalPath() + File.separator;
        int entryCount = 0;
        long extractedBytes = 0;

        try (ZipInputStream zip = new ZipInputStream(new BufferedInputStream(source))) {
            ZipEntry entry;
            byte[] buffer = new byte[8192];

            while ((entry = zip.getNextEntry()) != null) {
                entryCount++;
                if (entryCount > MAX_ZIP_ENTRIES) {
                    throw new IOException("ZIP contains too many entries");
                }
                File output = new File(destinationRoot, entry.getName());
                String outputPath = output.getCanonicalPath();

                if (!outputPath.startsWith(rootPath)) {
                    throw new IOException("Unsafe ZIP entry: " + entry.getName());
                }

                if (entry.isDirectory()) {
                    if (!output.mkdirs() && !output.isDirectory()) {
                        throw new IOException("Unable to create directory");
                    }
                    continue;
                }

                File parent = output.getParentFile();
                if (parent != null && !parent.exists() && !parent.mkdirs()) {
                    throw new IOException("Unable to create extraction directory");
                }

                try (BufferedOutputStream fileOut = new BufferedOutputStream(new FileOutputStream(output))) {
                    int count;
                    while ((count = zip.read(buffer)) != -1) {
                        extractedBytes += count;
                        if (extractedBytes > MAX_EXTRACTED_BYTES) {
                            throw new IOException("Extracted package exceeds the 2 GB limit");
                        }
                        fileOut.write(buffer, 0, count);
                    }
                }
            }
        }
    }

    private static void copyRecursively(File source, File destination) throws IOException {
        if (source.isDirectory()) {
            if (!destination.exists() && !destination.mkdirs()) {
                throw new IOException("Unable to create destination directory");
            }

            File[] children = source.listFiles();
            if (children != null) {
                for (File child : children) {
                    copyRecursively(child, new File(destination, child.getName()));
                }
            }
            return;
        }

        File parent = destination.getParentFile();
        if (parent != null && !parent.exists() && !parent.mkdirs()) {
            throw new IOException("Unable to create destination parent");
        }

        try (BufferedInputStream in = new BufferedInputStream(new FileInputStream(source));
             BufferedOutputStream out = new BufferedOutputStream(new FileOutputStream(destination))) {
            byte[] buffer = new byte[8192];
            int count;
            while ((count = in.read(buffer)) != -1) {
                out.write(buffer, 0, count);
            }
        }
    }

    public static void deleteRecursively(File target) {
        if (target == null || !target.exists()) {
            return;
        }

        if (target.isDirectory()) {
            File[] children = target.listFiles();
            if (children != null) {
                for (File child : children) {
                    deleteRecursively(child);
                }
            }
        }

        target.delete();
    }
}
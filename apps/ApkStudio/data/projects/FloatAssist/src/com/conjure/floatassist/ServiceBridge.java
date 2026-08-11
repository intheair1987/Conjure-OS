package com.conjure.floatassist;

import android.content.Context;
import android.webkit.JavascriptInterface;

public class ServiceBridge {
    private FloatService service;

    public ServiceBridge(FloatService service) {
        this.service = service;
    }

    @JavascriptInterface
    public void closeConsole() {
        service.collapseMenuFromBridge();
    }

    @JavascriptInterface
    public void openSettings() {
        try {
            android.content.Intent intent = new android.content.Intent(service, MainActivity.class);
            intent.addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK);
            service.startActivity(intent);
            service.collapseMenuFromBridge();
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    @JavascriptInterface
    public void openConjureOs() {
        try {
            android.content.Intent intent = service.getPackageManager().getLaunchIntentForPackage("com.conjure.runtime");
            if (intent == null) {
                intent = new android.content.Intent("com.conjure.runtime.OPEN_CONJURE_OS");
                intent.setPackage("com.conjure.runtime");
            }
            intent.addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK | android.content.Intent.FLAG_ACTIVITY_REORDER_TO_FRONT | android.content.Intent.FLAG_ACTIVITY_SINGLE_TOP);
            service.startActivity(intent);
            service.collapseMenuFromBridge();
        } catch (Exception e) {
            e.printStackTrace();
            try {
                service.executeSmartCommand("am start -n com.conjure.runtime/.MainActivity");
                service.collapseMenuFromBridge();
            } catch (Exception ex) {
                ex.printStackTrace();
            }
        }
    }

    @JavascriptInterface
    public void animateCollapse(long durationMs) {
        service.animateCollapseFromBridge(durationMs);
    }

    @JavascriptInterface
    public void toggleFlashlight() {
        service.toggleFlashlightFromBridge();
    }

    @JavascriptInterface
    public boolean isFlashlightOn() {
        return service.isFlashlightOn();
    }

    @JavascriptInterface
    public String executeTermuxCommand(String command) {
        return service.runTermuxCommand(command);
    }

    @JavascriptInterface
    public String executeSmartCommand(String command) {
        return service.executeSmartCommand(command);
    }

    @JavascriptInterface
    public String getSettingString(String key, String defValue) {
        return FloatSettingsStore.getString(service, key, defValue);
    }

    @JavascriptInterface
    public int getSettingInt(String key, int defValue) {
        return FloatSettingsStore.getInt(service, key, defValue);
    }

    @JavascriptInterface
    public String getShortcutsJson() {
        return FloatSettingsStore.getShortcutsJson(service);
    }

    @JavascriptInterface
    public void saveShortcutsJson(String jsonStr) {
        FloatSettingsStore.saveShortcutsJson(service, jsonStr);
    }

    @JavascriptInterface
    public String getRuntimeActiveJson() {
        org.json.JSONObject manifest = FloatSettingsStore.getRuntimeActiveManifest();
        return manifest != null ? manifest.toString() : "{}";
    }

    @JavascriptInterface
    public String getClipboardText() {
        try {
            final String[] result = new String[]{""};
            final java.util.concurrent.CountDownLatch latch = new java.util.concurrent.CountDownLatch(1);
            new android.os.Handler(android.os.Looper.getMainLooper()).post(new Runnable() {
                @Override
                public void run() {
                    try {
                        android.content.ClipboardManager clipboard = (android.content.ClipboardManager) service.getSystemService(Context.CLIPBOARD_SERVICE);
                        if (clipboard != null && clipboard.hasPrimaryClip() && clipboard.getPrimaryClip().getItemCount() > 0) {
                            CharSequence text = clipboard.getPrimaryClip().getItemAt(0).getText();
                            if (text != null) {
                                result[0] = text.toString();
                            }
                        }
                    } catch (Exception e) {
                        e.printStackTrace();
                    } finally {
                        latch.countDown();
                    }
                }
            });
            latch.await(1, java.util.concurrent.TimeUnit.SECONDS);
            return result[0];
        } catch (Exception e) {
            e.printStackTrace();
            return "";
        }
    }

    @JavascriptInterface
    public void copyToClipboard(final String text) {
        new android.os.Handler(android.os.Looper.getMainLooper()).post(new Runnable() {
            @Override
            public void run() {
                try {
                    android.content.ClipboardManager clipboard = (android.content.ClipboardManager) service.getSystemService(Context.CLIPBOARD_SERVICE);
                    android.content.ClipData clip = android.content.ClipData.newPlainText("Patcher Report", text);
                    if (clipboard != null) {
                        clipboard.setPrimaryClip(clip);
                    }
                } catch (Exception e) {
                    e.printStackTrace();
                }
            }
        });
    }
}
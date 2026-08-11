package com.conjure.runtime;

import android.webkit.JavascriptInterface;

public final class RuntimeBridge {
    private final MainActivity activity;

    public RuntimeBridge(MainActivity activity) {
        this.activity = activity;
    }

    @JavascriptInterface
    public void selectConjureZip() {
        activity.openConjureZipPicker();
    }

    @JavascriptInterface
    public boolean hasExistingDefaultPackage() {
        return activity.hasExistingDefaultPackage();
    }

    @JavascriptInterface
    public String getInstallStatus() {
        return activity.getInstallStatusJson();
    }

    @JavascriptInterface
    public void downloadConjureZip(String url) {
        activity.downloadConjureZip(url);
    }

    @JavascriptInterface
    public void startRuntime() {
        activity.startRuntime();
    }

    @JavascriptInterface
    public void stopRuntime() {
        activity.stopRuntime();
    }

    @JavascriptInterface
    public void copyLogs() {
        activity.copyLogsToClipboard();
    }

    @JavascriptInterface
    public void clearLogs() {
        activity.clearLogs();
    }

    @JavascriptInterface
    public void downloadRootCa() {
        activity.downloadRootCa();
    }

    @JavascriptInterface
    public void openMainSettings() {
        activity.openMainSettings();
    }

    @JavascriptInterface
    public void setCustomPorts(int httpsPort, int httpPort) {
        activity.setCustomPorts(httpsPort, httpPort);
    }

    @JavascriptInterface
    public String getCustomPorts() {
        return activity.getCustomPortsJson();
    }

    @JavascriptInterface
    public String getActiveNetworkIps() {
        return activity.getActiveNetworkIpsJson();
    }

    @JavascriptInterface
    public void openConjureOS() {
        activity.openConjureOS();
    }

    @JavascriptInterface
    public String getMdnsStatus() {
        return activity.getMdnsStatusJson();
    }

    @JavascriptInterface
    public String getMdnsLog() {
        return activity.getMdnsLog();
    }

    @JavascriptInterface
    public String getRuntimeStatus() {
        return activity.getRuntimeStatusJson();
    }

    @JavascriptInterface
    public String getRuntimeBundleInfo() {
        return activity.getRuntimeBundleInfoJson();
    }

    @JavascriptInterface
    public void startTailscale() {
        activity.startTailscale();
    }

    @JavascriptInterface
    public void stopTailscale() {
        activity.stopTailscale();
    }

    @JavascriptInterface
    public String getTailscaleStatus() {
        return activity.getTailscaleStatusJson();
    }

    @JavascriptInterface
    public void openTailscaleAuthUrl() {
        activity.openTailscaleAuthUrl();
    }

    @JavascriptInterface
    public String getTailscaleLog() {
        return activity.getTailscaleLog();
    }

    @JavascriptInterface
    public void openExternalUrl(String url) {
        activity.openExternalUrl(url);
    }

    @JavascriptInterface
    public void saveTailscaleApiKey(String apiKey) {
        activity.saveTailscaleApiKey(apiKey, "");
    }

    @JavascriptInterface
    public void saveTailscaleApiKey(String apiKey, String tags) {
        activity.saveTailscaleApiKey(apiKey, tags);
    }

    @JavascriptInterface
    public String getTailscaleApiKey() {
        return activity.getTailscaleApiKeyJson();
    }

    @JavascriptInterface
    public boolean hasStoragePermission() {
        return activity.hasStoragePermission();
    }

    @JavascriptInterface
    public void requestStoragePermission() {
        activity.requestStoragePermission();
    }

    @JavascriptInterface
    public boolean isBatteryOptimizationIgnored() {
        return activity.isBatteryOptimizationIgnored();
    }

    @JavascriptInterface
    public void requestIgnoreBatteryOptimizations() {
        activity.requestIgnoreBatteryOptimizations();
    }

    @JavascriptInterface
    public void resetTailscaleNode() {
        activity.resetTailscaleNode();
    }

    @JavascriptInterface
    public void logoutTailscale() {
        activity.logoutTailscale();
    }

    @JavascriptInterface
    public void restartApp() {
        activity.restartApp();
    }

    @JavascriptInterface
    public void restartServices() {
        activity.restartRuntimeAndTailscale();
    }

    @JavascriptInterface
    public void setAutoStartSettings(boolean autoStartLaunch, boolean autoStartBoot, boolean autoStartTailscale) {
        activity.setAutoStartSettings(autoStartLaunch, autoStartBoot, autoStartTailscale);
    }

    @JavascriptInterface
    public String getAutoStartSettings() {
        return activity.getAutoStartSettingsJson();
    }

    @JavascriptInterface
    public void dismissCollisionFlag() {
        activity.dismissCollisionFlag();
    }

    @JavascriptInterface
    public void setOpenConjureOsByDefault(boolean enabled) {
        activity.setOpenConjureOsByDefault(enabled);
    }

    @JavascriptInterface
    public boolean getOpenConjureOsByDefault() {
        return activity.getOpenConjureOsByDefault();
    }

    @JavascriptInterface
    public void openWrapperSettings() {
        activity.openWrapperSettingsFromUi();
    }

    @JavascriptInterface
    public void closeChildOverlay() {
        activity.closeChildOverlayFromUi();
    }

    @JavascriptInterface
    public void openConjureOsWrapper() {
        activity.openConjureOsWrapperFromUi();
    }

    @JavascriptInterface
    public void setInterceptBackButton(boolean enabled) {
        activity.setInterceptBackButton(enabled);
    }

    @JavascriptInterface
    public boolean getInterceptBackButton() {
        return activity.getInterceptBackButton();
    }

    @JavascriptInterface
    public void vibrate(long ms) {
        activity.vibrate(ms);
    }

    @JavascriptInterface
    public void clearAllSiteData() {
        activity.clearAllSiteData();
    }

    @JavascriptInterface
    public void clearCacheOnly() {
        activity.clearCacheOnly();
    }

    @JavascriptInterface
    public void clearWebStorageOnly() {
        activity.clearWebStorageOnly();
    }

    @JavascriptInterface
    public void clearCookiesOnly() {
        activity.clearCookiesOnly();
    }

    @JavascriptInterface
    public String getWrapperSettings() {
        return activity.getWrapperSettingsJson();
    }

    @JavascriptInterface
    public void saveWrapperSettings(String themeMode, String customUrl, boolean resumeLastUrl, boolean confirmResume, String linkMode, boolean multiTabMode, boolean forceZoom) {
        activity.saveWrapperSettings(themeMode, customUrl, resumeLastUrl, confirmResume, linkMode, multiTabMode, forceZoom, false);
    }

    @JavascriptInterface
    public void saveWrapperSettings(String themeMode, String customUrl, boolean resumeLastUrl, boolean confirmResume, String linkMode, boolean multiTabMode, boolean forceZoom, boolean enableShake) {
        activity.saveWrapperSettings(themeMode, customUrl, resumeLastUrl, confirmResume, linkMode, multiTabMode, forceZoom, enableShake);
    }

    @JavascriptInterface
    public String getSystemPaths() {
        return activity.getSystemPathsJson();
    }

    @JavascriptInterface
    public void setActiveSystemPath(String path) {
        activity.setActiveSystemPath(path);
    }

    @JavascriptInterface
    public void addSystemPath(String path) {
        activity.addSystemPath(path);
    }

    @JavascriptInterface
    public void removeSystemPath(String path) {
        activity.removeSystemPath(path);
    }

    @JavascriptInterface
    public void openFolderPicker() {
        activity.openFolderPicker();
    }

    @JavascriptInterface
    public void processBlobDownload(String dataUrl, String contentDisposition, String mimeType) {
        activity.processBlobDownload(dataUrl, contentDisposition, mimeType);
    }
}
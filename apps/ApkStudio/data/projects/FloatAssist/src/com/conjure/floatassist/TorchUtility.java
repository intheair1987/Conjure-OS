package com.conjure.floatassist;

import android.content.Context;
import android.hardware.camera2.CameraManager;
import android.os.Build;

public class TorchUtility {
    private boolean isTorchOn = false;
    private String cameraId;
    private CameraManager cameraManager;

    public TorchUtility(Context context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            cameraManager = (CameraManager) context.getSystemService(Context.CAMERA_SERVICE);
            try {
                String[] list = cameraManager.getCameraIdList();
                if (list != null && list.length > 0) {
                    cameraId = list[0];
                }
            } catch (Exception e) {
                e.printStackTrace();
            }
        }
    }

    public boolean toggleTorch() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && cameraManager != null && cameraId != null) {
            try {
                isTorchOn = !isTorchOn;
                cameraManager.setTorchMode(cameraId, isTorchOn);
                return isTorchOn;
            } catch (Exception e) {
                e.printStackTrace();
                isTorchOn = false;
                return false;
            }
        }
        return false;
    }

    public void turnOff() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && cameraManager != null && cameraId != null && isTorchOn) {
            try {
                cameraManager.setTorchMode(cameraId, false);
            } catch (Exception e) {
                e.printStackTrace();
            }
            isTorchOn = false;
        }
    }

    public boolean isOn() {
        return isTorchOn;
    }
}
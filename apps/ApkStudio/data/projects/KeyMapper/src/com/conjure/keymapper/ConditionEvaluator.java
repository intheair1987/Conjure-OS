package com.conjure.keymapper;

import android.app.KeyguardManager;
import android.app.NotificationManager;
import android.content.Context;
import android.content.res.Configuration;
import android.hardware.Sensor;
import android.hardware.SensorEvent;
import android.hardware.SensorEventListener;
import android.hardware.SensorManager;
import android.media.AudioManager;
import android.os.Build;
import android.os.PowerManager;
import android.provider.Settings;
import android.view.Display;
import android.view.Surface;
import android.view.WindowManager;
import org.json.JSONArray;
import org.json.JSONObject;

public class ConditionEvaluator {
    private static float lastHingeAngle = 180f;
    private static boolean hasReceivedHingeReading = false;
    private static boolean hingeSensorRegistered = false;

    public static void initSensors(Context context) {
        if (hingeSensorRegistered || context == null) return;
        try {
            SensorManager sensorManager = (SensorManager) context.getSystemService(Context.SENSOR_SERVICE);
            if (sensorManager != null) {
                java.util.List<Sensor> sensors = sensorManager.getSensorList(Sensor.TYPE_ALL);
                for (Sensor s : sensors) {
                    if (s == null) continue;
                    String name = s.getName().toLowerCase();
                    if (s.getType() == 36 || name.contains("hinge") || name.contains("fold") || name.contains("flip")) {
                        sensorManager.registerListener(new SensorEventListener() {
                            @Override
                            public void onSensorChanged(SensorEvent event) {
                                if (event.values != null && event.values.length > 0) {
                                    lastHingeAngle = event.values[0];
                                    hasReceivedHingeReading = true;
                                }
                            }

                            @Override
                            public void onAccuracyChanged(Sensor sensor, int accuracy) {}
                        }, s, SensorManager.SENSOR_DELAY_NORMAL);
                        hingeSensorRegistered = true;
                    }
                }
            }
        } catch (Exception ignored) {}
    }

    public static boolean evaluateAll(Context context, JSONArray conditionsArray, String activePackage) {
        if (conditionsArray == null || conditionsArray.length() == 0) {
            return true; // No conditions -> Always active
        }

        initSensors(context);

        try {
            for (int i = 0; i < conditionsArray.length(); i++) {
                JSONObject cond = conditionsArray.getJSONObject(i);
                String type = cond.optString("type", "");

                if (!evaluateSingle(context, type, cond, activePackage)) {
                    return false; // All conditions must pass
                }
            }
            return true;
        } catch (Exception e) {
            e.printStackTrace();
            return false;
        }
    }

    private static boolean evaluateSingle(Context context, String type, JSONObject cond, String activePackage) {
        switch (type) {
            case "screen_off":
                return isScreenOff(context);

            case "screen_folded":
                return isScreenFolded(context);

            case "upside_down":
                return isUpsideDown(context);

            case "lock_screen":
                return isOnLockScreen(context);

            case "in_app":
                String targetPkg = cond.optString("package", "").trim();
                return targetPkg.equalsIgnoreCase(activePackage);

            case "ringer_silent":
                return getRingerMode(context) == AudioManager.RINGER_MODE_SILENT;

            case "ringer_vibrate":
                return getRingerMode(context) == AudioManager.RINGER_MODE_VIBRATE;

            case "ringer_normal":
                return getRingerMode(context) == AudioManager.RINGER_MODE_NORMAL;

            case "dnd_on":
                return isDndOn(context);

            case "is_recording":
                return AudioRecorderEngine.isRecording();

            case "is_not_recording":
                return !AudioRecorderEngine.isRecording();

            default:
                return true;
        }
    }

    @SuppressWarnings("deprecation")
    private static boolean isScreenOff(Context context) {
        PowerManager pm = (PowerManager) context.getSystemService(Context.POWER_SERVICE);
        if (pm != null) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.KITKAT_WATCH) {
                return !pm.isInteractive();
            } else {
                return !pm.isScreenOn();
            }
        }
        return false;
    }

    private static boolean isScreenFolded(Context context) {
        initSensors(context);

        // 1. Check direct Hinge Angle Sensor (< 90 degrees = folded/closed)
        if (hasReceivedHingeReading && lastHingeAngle < 90f) {
            return true;
        }

        // 2. Check Screen Layout Size Configuration (Cover screens report SMALL size)
        try {
            Configuration config = context.getResources().getConfiguration();
            if (config != null) {
                int layout = config.screenLayout & Configuration.SCREENLAYOUT_SIZE_MASK;
                if (layout == Configuration.SCREENLAYOUT_SIZE_SMALL) {
                    return true;
                }
            }
        } catch (Exception ignored) {}

        return false;
    }

    private static boolean isUpsideDown(Context context) {
        WindowManager wm = (WindowManager) context.getSystemService(Context.WINDOW_SERVICE);
        if (wm != null) {
            Display display = wm.getDefaultDisplay();
            if (display != null) {
                return display.getRotation() == Surface.ROTATION_180;
            }
        }
        return false;
    }

    private static boolean isOnLockScreen(Context context) {
        KeyguardManager km = (KeyguardManager) context.getSystemService(Context.KEYGUARD_SERVICE);
        if (km != null) {
            return km.isKeyguardLocked();
        }
        return false;
    }

    private static int getRingerMode(Context context) {
        try {
            AudioManager am = (AudioManager) context.getSystemService(Context.AUDIO_SERVICE);
            if (am != null) {
                return am.getRingerMode();
            }
        } catch (Exception ignored) {}
        return AudioManager.RINGER_MODE_NORMAL;
    }

    private static boolean isDndOn(Context context) {
        try {
            NotificationManager nm = (NotificationManager) context.getSystemService(Context.NOTIFICATION_SERVICE);
            if (nm != null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                int filter = nm.getCurrentInterruptionFilter();
                if (filter != NotificationManager.INTERRUPTION_FILTER_ALL && filter != NotificationManager.INTERRUPTION_FILTER_UNKNOWN) {
                    return true;
                }
            }
            int zenMode = Settings.Global.getInt(context.getContentResolver(), "zen_mode", 0);
            return zenMode != 0;
        } catch (Exception ignored) {}
        return false;
    }
}
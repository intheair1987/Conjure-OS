package com.conjure.keymapper;

import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.hardware.camera2.CameraManager;
import android.media.AudioFormat;
import android.media.AudioManager;
import android.media.AudioTrack;
import android.media.ToneGenerator;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.os.Vibrator;
import android.os.VibrationEffect;
import org.json.JSONArray;
import org.json.JSONObject;

public class ActionExecutor {
    private static boolean realTorchState = false;

    public static void setTorchState(boolean enabled) {
        realTorchState = enabled;
    }

    public static void executeActionsSequence(final Context context, final JSONArray actionsArray) {
        executeActionsSequence(context, actionsArray, "");
    }

    public static void executeActionsSequence(final Context context, final JSONArray actionsArray, final String matchedSetId) {
        if (actionsArray == null || actionsArray.length() == 0 || context == null) return;

        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    for (int i = 0; i < actionsArray.length(); i++) {
                        JSONObject actObj = actionsArray.getJSONObject(i);
                        if (!isActionAssignedToSet(actObj, matchedSetId)) {
                            continue;
                        }

                        String actType = actObj.optString("type", "");

                        if ("wait".equalsIgnoreCase(actType) || "delay".equalsIgnoreCase(actType)) {
                            long ms = actObj.optLong("durationMs", 500);
                            if (ms > 0) {
                                Thread.sleep(ms);
                            }
                        } else {
                            executeActionObject(context, actObj);
                        }
                    }
                } catch (Exception e) {
                    e.printStackTrace();
                }
            }
        }).start();
    }

    public static boolean isActionAssignedToSet(JSONObject actObj, String matchedSetId) {
        if (actObj == null) return true;
        if (matchedSetId == null || matchedSetId.isEmpty()) return true;

        JSONArray targetSetIds = actObj.optJSONArray("targetSetIds");
        if (targetSetIds == null || targetSetIds.length() == 0) {
            return true;
        }

        try {
            for (int i = 0; i < targetSetIds.length(); i++) {
                String targetId = targetSetIds.optString(i, "");
                if ("all".equalsIgnoreCase(targetId) || matchedSetId.equalsIgnoreCase(targetId)) {
                    return true;
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return false;
    }

    public static void executeActionObject(Context context, JSONObject actObj) {
        if (actObj == null) return;
        String type = actObj.optString("type", "");
        if ("start_recording".equalsIgnoreCase(type)) {
            long intervalMs = actObj.optLong("intervalMs", 3000);
            long pulseDurationMs = actObj.optLong("pulseDurationMs", 100);
            int pulseAmplitude = actObj.optInt("pulseAmplitude", 128);
            int bitRate = actObj.optInt("bitRate", 64000);
            int sampleRate = actObj.optInt("sampleRate", 16000);
            String format = actObj.optString("format", "m4a");
            AudioRecorderEngine.startRecording(context, intervalMs, pulseDurationMs, pulseAmplitude, bitRate, sampleRate, format);
        } else if ("stop_recording".equalsIgnoreCase(type)) {
            AudioRecorderEngine.stopRecording(context);
        } else if ("start_conjure_record".equalsIgnoreCase(type)) {
            RemoteUploaderEngine.startConjureRecording(context, actObj);
        } else if ("stop_conjure_record".equalsIgnoreCase(type)) {
            RemoteUploaderEngine.stopConjureRecording(context);
        } else if ("vibrate".equalsIgnoreCase(type)) {
            long ms = actObj.optLong("durationMs", 250);
            int amp = actObj.optInt("pulseAmplitude", 128);
            vibrateDevice(context, ms, amp);
        } else if ("play_sound".equalsIgnoreCase(type)) {
            String soundType = actObj.optString("soundType", "beep");
            playSoundChime(soundType);
        } else if ("paste".equalsIgnoreCase(type)) {
            String mode = actObj.optString("mode", "clipboard");
            String customText = actObj.optString("customText", "");
            executePasteAction(context, mode, customText);
        } else {
            executeAction(context, type);
        }
    }

    public static void executePasteAction(final Context context, final String mode, final String customText) {
        if (context == null) return;
        new Handler(Looper.getMainLooper()).post(new Runnable() {
            @Override
            public void run() {
                try {
                    if ("custom_text".equalsIgnoreCase(mode) && customText != null && !customText.isEmpty()) {
                        ClipboardManager clipboard = (ClipboardManager) context.getSystemService(Context.CLIPBOARD_SERVICE);
                        if (clipboard != null) {
                            ClipData clip = ClipData.newPlainText("KeyMapper Paste", customText);
                            clipboard.setPrimaryClip(clip);
                        }
                    }
                    new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
                        @Override
                        public void run() {
                            KeyAccessibilityService.performPasteAction();
                        }
                    }, 50);
                } catch (Exception e) {
                    e.printStackTrace();
                }
            }
        });
    }

    public static void executeAction(Context context, String actionType) {
        if (actionType == null) return;

        switch (actionType) {
            case "toggle_flashlight":
                setTorchMode(context, !realTorchState);
                break;
            case "turn_flashlight_off":
                setTorchMode(context, false);
                break;
            case "turn_flashlight_on":
                setTorchMode(context, true);
                break;
            case "play_sound":
                playSoundChime();
                break;
            case "vibrate":
                vibrateDevice(context, 250);
                break;
            case "start_recording":
                AudioRecorderEngine.startRecording(context, 3000);
                break;
            case "stop_recording":
                AudioRecorderEngine.stopRecording(context);
                break;
        }
    }

    public static void toggleFlashlight(Context context) {
        setTorchMode(context, !realTorchState);
    }

    public static void setTorchMode(Context context, boolean targetState) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            try {
                CameraManager cameraManager = (CameraManager) context.getSystemService(Context.CAMERA_SERVICE);
                if (cameraManager != null) {
                    String cameraId = cameraManager.getCameraIdList()[0];
                    realTorchState = targetState;
                    cameraManager.setTorchMode(cameraId, targetState);
                }
            } catch (Exception e) {
                e.printStackTrace();
            }
        }
    }

    public static void playSoundChime() {
        playSoundChime("beep");
    }

    public static void playSoundChime(String soundType) {
        if (soundType == null) soundType = "beep";

        if ("coin".equalsIgnoreCase(soundType) || "jump".equalsIgnoreCase(soundType) ||
            "powerup".equalsIgnoreCase(soundType) || "laser".equalsIgnoreCase(soundType) ||
            "levelup".equalsIgnoreCase(soundType) || "gameover".equalsIgnoreCase(soundType) ||
            "hit".equalsIgnoreCase(soundType) || "oneup".equalsIgnoreCase(soundType) ||
            "treasure".equalsIgnoreCase(soundType) || "secret".equalsIgnoreCase(soundType) ||
            "wood_tap".equalsIgnoreCase(soundType) || "air_pop".equalsIgnoreCase(soundType) ||
            "glass_tap".equalsIgnoreCase(soundType) || "tactile_tick".equalsIgnoreCase(soundType) ||
            "gentle_drop".equalsIgnoreCase(soundType) || "magnetic_snap".equalsIgnoreCase(soundType) ||
            "whisper_ping".equalsIgnoreCase(soundType) || "water_drip".equalsIgnoreCase(soundType) ||
            "warm_bloom".equalsIgnoreCase(soundType) || "soft_lock".equalsIgnoreCase(soundType)) {
            playSynthSound(soundType);
            return;
        }

        try {
            ToneGenerator toneGen = new ToneGenerator(AudioManager.STREAM_MUSIC, 100);
            if ("bell".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_PROP_BEEP2, 300);
            } else if ("success".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_SUP_CONFIRM, 250);
            } else if ("alert".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_CDMA_ALERT_CALL_GUARD, 250);
            } else if ("ascending".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_PROP_ACK, 250);
            } else if ("pip".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_SUP_PIP, 180);
            } else if ("nack".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_PROP_NACK, 250);
            } else if ("cdma_confirm".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_CDMA_CONFIRM, 200);
            } else if ("radio_ack".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_SUP_RADIO_ACK, 220);
            } else if ("call_waiting".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_CDMA_NETWORK_CALLWAITING, 250);
            } else if ("abbr_alert".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_CDMA_ABBR_ALERT, 200);
            } else if ("radio_unavail".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_SUP_RADIO_NOTAVAIL, 250);
            } else if ("dtmf_5".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_DTMF_5, 200);
            } else if ("dtmf_9".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_DTMF_9, 200);
            } else if ("dtmf_s".equalsIgnoreCase(soundType)) {
                toneGen.startTone(ToneGenerator.TONE_DTMF_S, 200);
            } else {
                toneGen.startTone(ToneGenerator.TONE_PROP_BEEP, 200);
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    @SuppressWarnings("deprecation")
    private static void playSynthSound(final String type) {
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    int sampleRate = 22050;
                    short[] pcm = createSynthBuffer(type, sampleRate);
                    if (pcm == null || pcm.length == 0) return;

                    int minBufSize = AudioTrack.getMinBufferSize(
                        sampleRate,
                        AudioFormat.CHANNEL_OUT_MONO,
                        AudioFormat.ENCODING_PCM_16BIT
                    );

                    AudioTrack track = new AudioTrack(
                        AudioManager.STREAM_MUSIC,
                        sampleRate,
                        AudioFormat.CHANNEL_OUT_MONO,
                        AudioFormat.ENCODING_PCM_16BIT,
                        Math.max(minBufSize, pcm.length * 2),
                        AudioTrack.MODE_STATIC
                    );

                    track.write(pcm, 0, pcm.length);
                    track.play();

                    long durationMs = (long) (((float) pcm.length / sampleRate) * 1000) + 100;
                    Thread.sleep(durationMs);
                    track.stop();
                    track.release();
                } catch (Exception e) {
                    e.printStackTrace();
                }
            }
        }).start();
    }

    private static short[] createSynthBuffer(String type, int sampleRate) {
        if ("coin".equalsIgnoreCase(type)) {
            return synthNotes(sampleRate, new float[]{988f, 1318f}, new float[]{0.07f, 0.18f}, "square");
        } else if ("jump".equalsIgnoreCase(type)) {
            return synthSweep(sampleRate, 150f, 650f, 0.15f, "square");
        } else if ("powerup".equalsIgnoreCase(type)) {
            return synthNotes(sampleRate, new float[]{523f, 659f, 784f, 1046f, 1318f}, new float[]{0.05f, 0.05f, 0.05f, 0.05f, 0.08f}, "square");
        } else if ("laser".equalsIgnoreCase(type)) {
            return synthSweep(sampleRate, 1200f, 180f, 0.12f, "saw");
        } else if ("levelup".equalsIgnoreCase(type)) {
            return synthNotes(sampleRate, new float[]{392f, 523f, 659f, 784f, 1046f}, new float[]{0.08f, 0.08f, 0.08f, 0.08f, 0.25f}, "triangle");
        } else if ("gameover".equalsIgnoreCase(type)) {
            return synthNotes(sampleRate, new float[]{659f, 622f, 587f, 554f}, new float[]{0.12f, 0.12f, 0.12f, 0.25f}, "saw");
        } else if ("hit".equalsIgnoreCase(type)) {
            return synthSweep(sampleRate, 300f, 60f, 0.09f, "saw");
        } else if ("oneup".equalsIgnoreCase(type)) {
            return synthNotes(sampleRate, new float[]{659f, 784f, 1318f, 1046f, 1175f, 1568f}, new float[]{0.06f, 0.06f, 0.06f, 0.06f, 0.06f, 0.15f}, "square");
        } else if ("treasure".equalsIgnoreCase(type)) {
            return synthNotes(sampleRate, new float[]{698f, 880f, 1046f, 1397f}, new float[]{0.06f, 0.06f, 0.06f, 0.2f}, "sine");
        } else if ("secret".equalsIgnoreCase(type)) {
            return synthNotes(sampleRate, new float[]{392f, 370f, 311f, 220f, 207f}, new float[]{0.08f, 0.08f, 0.08f, 0.08f, 0.2f}, "sine");
        } else if ("wood_tap".equalsIgnoreCase(type)) {
            return synthNotes(sampleRate, new float[]{320f}, new float[]{0.035f}, "sine");
        } else if ("air_pop".equalsIgnoreCase(type)) {
            return synthSweep(sampleRate, 480f, 240f, 0.025f, "sine");
        } else if ("glass_tap".equalsIgnoreCase(type)) {
            return synthNotes(sampleRate, new float[]{1200f}, new float[]{0.04f}, "sine");
        } else if ("tactile_tick".equalsIgnoreCase(type)) {
            return synthNotes(sampleRate, new float[]{520f}, new float[]{0.015f}, "square");
        } else if ("gentle_drop".equalsIgnoreCase(type)) {
            return synthSweep(sampleRate, 360f, 180f, 0.06f, "sine");
        } else if ("magnetic_snap".equalsIgnoreCase(type)) {
            return synthDualPulse(sampleRate, 600f, 0.01f, 800f, 0.015f, 0.01f);
        } else if ("whisper_ping".equalsIgnoreCase(type)) {
            return synthNotes(sampleRate, new float[]{680f}, new float[]{0.05f}, "sine");
        } else if ("water_drip".equalsIgnoreCase(type)) {
            return synthSweep(sampleRate, 540f, 720f, 0.045f, "sine");
        } else if ("warm_bloom".equalsIgnoreCase(type)) {
            return synthChord(sampleRate, new float[]{330f, 495f}, 0.08f);
        } else if ("soft_lock".equalsIgnoreCase(type)) {
            return synthDualPulse(sampleRate, 280f, 0.012f, 340f, 0.015f, 0.006f);
        }
        return null;
    }

    private static short[] synthDualPulse(int sampleRate, float f1, float d1, float f2, float d2, float gap) {
        int s1 = (int) (sampleRate * d1);
        int sGap = (int) (sampleRate * gap);
        int s2 = (int) (sampleRate * d2);
        short[] pcm = new short[s1 + sGap + s2];

        for (int i = 0; i < s1; i++) {
            float t = (float) i / sampleRate;
            float env = 1.0f - ((float) i / s1);
            pcm[i] = (short) (Math.sin(2.0 * Math.PI * f1 * t) * env * 6000);
        }
        for (int i = 0; i < s2; i++) {
            float t = (float) i / sampleRate;
            float env = 1.0f - ((float) i / s2);
            pcm[s1 + sGap + i] = (short) (Math.sin(2.0 * Math.PI * f2 * t) * env * 6000);
        }
        return pcm;
    }

    private static short[] synthChord(int sampleRate, float[] freqs, float duration) {
        int numSamples = (int) (sampleRate * duration);
        short[] pcm = new short[numSamples];

        for (int i = 0; i < numSamples; i++) {
            float t = (float) i / sampleRate;
            float env = 1.0f - ((float) i / numSamples);
            double sum = 0;
            for (float f : freqs) {
                sum += Math.sin(2.0 * Math.PI * f * t);
            }
            pcm[i] = (short) ((sum / freqs.length) * env * 7000);
        }
        return pcm;
    }

    private static short[] synthNotes(int sampleRate, float[] freqs, float[] durations, String waveform) {
        int totalSamples = 0;
        for (float d : durations) {
            totalSamples += (int) (sampleRate * d);
        }
        short[] pcm = new short[totalSamples];
        int offset = 0;

        for (int i = 0; i < freqs.length; i++) {
            float freq = freqs[i];
            float dur = durations[i];
            int numSamples = (int) (sampleRate * dur);

            for (int step = 0; step < numSamples; step++) {
                float t = (float) step / sampleRate;
                float envelope = 1.0f - ((float) step / numSamples);
                double angle = 2.0 * Math.PI * freq * t;
                float val = 0f;

                if ("square".equalsIgnoreCase(waveform)) {
                    val = Math.sin(angle) >= 0 ? 0.3f : -0.3f;
                } else if ("saw".equalsIgnoreCase(waveform)) {
                    val = (float) (2.0 * (t * freq - Math.floor(t * freq + 0.5)));
                } else if ("triangle".equalsIgnoreCase(waveform)) {
                    val = (float) (2.0 * Math.abs(2.0 * (t * freq - Math.floor(t * freq + 0.5))) - 1.0);
                } else {
                    val = (float) Math.sin(angle);
                }

                short sampleVal = (short) (val * envelope * 12000);
                if (offset + step < pcm.length) {
                    pcm[offset + step] = sampleVal;
                }
            }
            offset += numSamples;
        }
        return pcm;
    }

    private static short[] synthSweep(int sampleRate, float startFreq, float endFreq, float duration, String waveform) {
        int numSamples = (int) (sampleRate * duration);
        short[] pcm = new short[numSamples];

        double phase = 0.0;
        for (int i = 0; i < numSamples; i++) {
            float progress = (float) i / numSamples;
            float currentFreq = startFreq + (endFreq - startFreq) * progress;
            float envelope = 1.0f - progress;

            phase += (2.0 * Math.PI * currentFreq) / sampleRate;
            float val = 0f;

            if ("square".equalsIgnoreCase(waveform)) {
                val = Math.sin(phase) >= 0 ? 0.3f : -0.3f;
            } else if ("saw".equalsIgnoreCase(waveform)) {
                val = (float) (Math.sin(phase) >= 0 ? 0.25f : -0.25f);
            } else {
                val = (float) Math.sin(phase);
            }

            pcm[i] = (short) (val * envelope * 12000);
        }
        return pcm;
    }

    public static void vibrateDevice(Context context, long ms) {
        vibrateDevice(context, ms, -1);
    }

    @SuppressWarnings("deprecation")
    public static void vibrateDevice(Context context, long ms, int amplitude) {
        try {
            Vibrator vibrator = (Vibrator) context.getSystemService(Context.VIBRATOR_SERVICE);
            if (vibrator != null && vibrator.hasVibrator()) {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    int safeAmp = (amplitude >= 1 && amplitude <= 255) ? amplitude : VibrationEffect.DEFAULT_AMPLITUDE;
                    vibrator.vibrate(VibrationEffect.createOneShot(ms, safeAmp));
                } else {
                    vibrator.vibrate(ms);
                }
            }
        } catch (Exception ignored) {}
    }
}
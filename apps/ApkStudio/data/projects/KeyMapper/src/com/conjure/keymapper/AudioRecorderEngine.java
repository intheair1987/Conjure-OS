package com.conjure.keymapper;

import android.content.Context;
import android.media.MediaRecorder;
import android.os.Handler;
import android.os.Looper;
import java.io.File;

public class AudioRecorderEngine {
    private static MediaRecorder mRecorder = null;
    private static boolean isRecording = false;
    private static File lastRecordedFile = null;
    private static final Handler mPulseHandler = new Handler(Looper.getMainLooper());
    private static Runnable mPulseRunnable = null;

    public static boolean isRecording() {
        return isRecording;
    }

    public static synchronized File getLatestRecordingFile() {
        return lastRecordedFile;
    }

    public static synchronized void startRecording(final Context context, final long intervalMs) {
        startRecording(context, intervalMs, 100, 128, 64000, 16000, "m4a");
    }

    public static synchronized void startRecording(final Context context, final long intervalMs, final long pulseDurationMs, final int pulseAmplitude, final int bitRate, final int sampleRate, final String format) {
        if (isRecording) {
            stopRecording(context);
        }

        try {
            File configDir = new File(android.os.Environment.getExternalStorageDirectory(), "Conjure_Config");
            File recDir = new File(configDir, "KeyMapper");
            if (!recDir.exists()) {
                recDir.mkdirs();
            }

            String ext = ".m4a";
            if ("amr".equalsIgnoreCase(format)) ext = ".amr";
            else if ("3gp".equalsIgnoreCase(format)) ext = ".3gp";

            File outFile = new File(recDir, "REC_" + System.currentTimeMillis() + ext);
            lastRecordedFile = outFile;

            mRecorder = new MediaRecorder();
            mRecorder.setAudioSource(MediaRecorder.AudioSource.MIC);

            if ("amr".equalsIgnoreCase(format)) {
                mRecorder.setOutputFormat(MediaRecorder.OutputFormat.AMR_NB);
                mRecorder.setAudioEncoder(MediaRecorder.AudioEncoder.AMR_NB);
            } else if ("3gp".equalsIgnoreCase(format)) {
                mRecorder.setOutputFormat(MediaRecorder.OutputFormat.THREE_GPP);
                mRecorder.setAudioEncoder(MediaRecorder.AudioEncoder.AMR_WB);
            } else {
                mRecorder.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4);
                mRecorder.setAudioEncoder(MediaRecorder.AudioEncoder.AAC);
            }

            if (bitRate > 0) {
                mRecorder.setAudioEncodingBitRate(bitRate);
            }
            if (sampleRate > 0) {
                mRecorder.setAudioSamplingRate(sampleRate);
            }

            mRecorder.setOutputFile(outFile.getAbsolutePath());
            mRecorder.prepare();
            mRecorder.start();

            // 1. Wait for initial file creation and container header write (up to 1.5s)
            long startWait = System.currentTimeMillis();
            while ((System.currentTimeMillis() - startWait) < 1500) {
                if (outFile.exists() && outFile.length() > 0) {
                    break;
                }
                try {
                    Thread.sleep(30);
                } catch (Exception ignored) {}
            }

            // 2. Capture initial container header size (e.g., ~3,800 bytes)
            long initialSize = outFile.exists() ? outFile.length() : 0;

            // 3. Physical File Growth Verifier:
            // Poll storage until live audio frame deltas exceed initialSize + 1500 bytes
            while ((System.currentTimeMillis() - startWait) < 3000) {
                long currentSize = outFile.exists() ? outFile.length() : 0;
                if (currentSize >= initialSize + 1500) {
                    break; // Active audio frames are physically accumulating on disk!
                }
                try {
                    Thread.sleep(40);
                } catch (Exception ignored) {}
            }

            // 4. Cushion delay for audio buffer stream continuity
            try {
                Thread.sleep(150);
            } catch (Exception ignored) {}

            isRecording = true;

            final long pDuration = pulseDurationMs > 0 ? pulseDurationMs : 100;
            final int pAmp = pulseAmplitude;

            // Immediate start vibration pulse
            ActionExecutor.vibrateDevice(context, Math.max(150, pDuration), pAmp);

            // Setup periodic vibration pulse while recording is running
            final long pulseInterval = intervalMs > 0 ? intervalMs : 3000;
            mPulseRunnable = new Runnable() {
                @Override
                public void run() {
                    if (isRecording) {
                        ActionExecutor.vibrateDevice(context, pDuration, pAmp);
                        mPulseHandler.postDelayed(this, pulseInterval);
                    }
                }
            };
            mPulseHandler.postDelayed(mPulseRunnable, pulseInterval);

        } catch (Exception e) {
            e.printStackTrace();
            isRecording = false;
            if (mRecorder != null) {
                try { mRecorder.release(); } catch (Exception ignored) {}
                mRecorder = null;
            }
        }
    }

    public static synchronized void stopRecording(final Context context) {
        if (!isRecording) return;

        isRecording = false;

        if (mPulseHandler != null && mPulseRunnable != null) {
            mPulseHandler.removeCallbacks(mPulseRunnable);
            mPulseRunnable = null;
        }

        if (mRecorder != null) {
            try {
                mRecorder.stop();
                mRecorder.release();
            } catch (Exception e) {
                e.printStackTrace();
            }
            mRecorder = null;
        }

        // Double vibration feedback confirming stop recording
        ActionExecutor.vibrateDevice(context, 100);
        mPulseHandler.postDelayed(new Runnable() {
            @Override
            public void run() {
                ActionExecutor.vibrateDevice(context, 100);
            }
        }, 200);
    }
}
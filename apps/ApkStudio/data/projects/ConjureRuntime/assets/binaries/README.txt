ConjureRuntime native bundle contract

Place executable runtime files in the ABI-specific directory used by the target device:

assets/binaries/arm64-v8a/php
assets/binaries/arm64-v8a/nginx

Additional supported ABI directories may include:

assets/binaries/armeabi-v7a/
assets/binaries/x86_64/
assets/binaries/x86/

Required executable names:

php
nginx

The files are extracted into the APK app-private runtime/bin/ directory at first runtime-service startup. Do not place placeholder text files at the executable paths.
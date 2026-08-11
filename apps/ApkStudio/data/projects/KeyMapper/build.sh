#!/usr/bin/env bash
# ApkStudio Build Script for KeyMapper

APP_NAME="KeyMapper"
PKG_NAME="com.conjure.keymapper"
PROJ_DIR=$(pwd)
BUILD_DIR="$PROJ_DIR/build"

set -e

echo "0. Checking dependencies..."
get_package_name() {
    case "$1" in
        zipalign) echo "aapt" ;;
        aapt2) echo "aapt" ;;
        termux-clipboard-set) echo "termux-api" ;;
        *) echo "$1" ;;
    esac
}

for cmd in aapt2 ecj d8 apksigner zip zipalign termux-clipboard-set; do
    if ! command -v $cmd &> /dev/null; then
        pkg_name=$(get_package_name "$cmd")
        echo "Installing missing package: $pkg_name (for $cmd)..."
        pkg install -y "$pkg_name"
    fi
done
if ! command -v java &> /dev/null; then
    echo "Installing openjdk-17..."
    pkg install -y openjdk-17
fi

ANDROID_JAR=$(find $PREFIX/share -name "android.jar" 2>/dev/null | head -n 1)
if [ -z "$ANDROID_JAR" ]; then
    if [ -f "/system/framework/framework.jar" ]; then
        ANDROID_JAR="/system/framework/framework.jar"
    else
        ANDROID_JAR="/system/framework/android.jar"
    fi
fi

echo "Cleaning build directory..."
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/gen" "$BUILD_DIR/obj" "$BUILD_DIR/apk"

echo "1. Compiling resources..."
aapt2 compile --dir res/ -o "$BUILD_DIR/compiled_res.zip"
aapt2 link "$BUILD_DIR/compiled_res.zip" \
    -I /system/framework/framework-res.apk \
    --manifest AndroidManifest.xml \
    --java "$BUILD_DIR/gen" \
    -o "$BUILD_DIR/apk/app-unsigned.apk"

echo "2. Compiling Java sources..."
ecj -d "$BUILD_DIR/obj" \
    -cp "$ANDROID_JAR" \
    $(find "$BUILD_DIR/gen" -name "*.java") \
    $(find src -name "*.java")

echo "3. Translating to Dalvik bytecode..."
d8 --lib "$ANDROID_JAR" --output "$BUILD_DIR/apk/" $(find "$BUILD_DIR/obj" -name "*.class")

echo "4. Packaging APK..."
cd "$BUILD_DIR/apk"
zip -ur app-unsigned.apk classes.dex
cd "$PROJ_DIR"

if [ -d "assets" ]; then
    echo "Bundling custom assets..."
    zip -ur "$BUILD_DIR/apk/app-unsigned.apk" assets/
fi

echo "4.5 Aligning APK..."
zipalign -f -p 4 "$BUILD_DIR/apk/app-unsigned.apk" "$BUILD_DIR/apk/app-aligned.apk"

echo "5. Signing APK..."
PERSISTENT_KEYSTORE="$HOME/.apkstudio/debug.keystore"
if [ ! -f "$PERSISTENT_KEYSTORE" ]; then
    mkdir -p "$HOME/.apkstudio"
    keytool -genkey -v -keystore "$PERSISTENT_KEYSTORE" -storepass android -alias androiddebugkey -keypass android -keyalg RSA -keysize 2048 -validity 10000 -dname "CN=Android Debug,O=Android,C=US"
fi

apksigner sign --ks "$PERSISTENT_KEYSTORE" --ks-pass pass:android --out "/sdcard/Download/${APP_NAME}-debug.apk" "$BUILD_DIR/apk/app-aligned.apk"

echo "6. Cleaning up staging artifacts..."
rm -rf "$BUILD_DIR"
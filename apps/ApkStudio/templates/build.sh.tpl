#!/usr/bin/env bash
# ApkStudio Build Script for {{APP_NAME}}

APP_NAME="{{APP_NAME}}"
PKG_NAME="{{PACKAGE_NAME}}"
PROJ_DIR=$(pwd)
BUILD_DIR="$PROJ_DIR/build"

set -e

echo "0. Checking Termux environment configurations..."
# Ensure the .termux folder exists
mkdir -p "$HOME/.termux"
PROPERTIES_FILE="$HOME/.termux/termux.properties"

# Securely enable allow-external-apps to allow termux-open dynamic file-sharing with Package Installer
if [ -f "$PROPERTIES_FILE" ]; then
    if ! grep -q "^allow-external-apps[[:space:]]*=[[:space:]]*true" "$PROPERTIES_FILE"; then
        echo "Enabling allow-external-apps setting in termux.properties..."
        if grep -q "allow-external-apps[[:space:]]*=" "$PROPERTIES_FILE"; then
            sed -i 's/^[[:space:]]*#[[:space:]]*\(allow-external-apps[[:space:]]*=[[:space:]]*true\)/\1/' "$PROPERTIES_FILE"
            sed -i 's/^allow-external-apps[[:space:]]*=[[:space:]]*.*/allow-external-apps = true/' "$PROPERTIES_FILE"
        else
            echo "allow-external-apps = true" >> "$PROPERTIES_FILE"
        fi
        termux-reload-settings
    fi
else
    echo "allow-external-apps = true" > "$PROPERTIES_FILE"
    termux-reload-settings
fi

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

# Locate the correct android.jar provided by Termux packages
ANDROID_JAR=$(find $PREFIX/share -name "android.jar" 2>/dev/null | head -n 1)
if [ -z "$ANDROID_JAR" ]; then
    if [ -f "/system/framework/framework.jar" ]; then
        ANDROID_JAR="/system/framework/framework.jar"
    else
        ANDROID_JAR="/system/framework/android.jar"
    fi
fi

# Execute project-specific pre-build preparation hook (bootstrapping)
if [ -f "build_pre.sh" ]; then
    echo "Executing project-specific pre-build hook..."
    chmod +x build_pre.sh
    ./build_pre.sh
fi

echo "Cleaning build directory..."
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/gen" "$BUILD_DIR/obj" "$BUILD_DIR/apk"

echo "1. Compiling resources..."
if [ -f "res/drawable/app_icon.png" ] && [ -f "res/drawable/app_icon.xml" ]; then
    echo "Resolving duplicate app_icon resource collision..."
    rm -f "res/drawable/app_icon.xml"
fi
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

if [ -d "lib" ]; then
    echo "Bundling native libraries (lib/)..."
    zip -ur "$BUILD_DIR/apk/app-unsigned.apk" lib/
fi

if [ -d "assets" ]; then
    echo "Bundling custom assets (assets/)..."
    zip -ur "$BUILD_DIR/apk/app-unsigned.apk" assets/
fi

echo "4.5 Aligning APK..."
zipalign -f -p 4 "$BUILD_DIR/apk/app-unsigned.apk" "$BUILD_DIR/apk/app-aligned.apk"

echo "5. Signing APK..."
if [ -f "custom.keystore" ] && [ -f "signing.conf" ] && ! grep -q 'SIGNING_MODE="debug"' signing.conf; then
    echo "Using custom uploaded keystore for signing..."
    source signing.conf
    apksigner sign --ks custom.keystore --ks-pass pass:"$KS_PASS" --key-pass pass:"$KEY_PASS" --ks-key-alias "$KEY_ALIAS" --out "/sdcard/Download/${APP_NAME}-debug.apk" "$BUILD_DIR/apk/app-aligned.apk"
else
    echo "Using default debug keystore..."
    PERSISTENT_KEYSTORE="$HOME/.apkstudio/debug.keystore"
    
    # Check if a previously exported backup exists in the shared Download folder
    if [ -f "/sdcard/Download/${APP_NAME}-debug.keystore" ]; then
        mkdir -p "$HOME/.apkstudio"
        cp "/sdcard/Download/${APP_NAME}-debug.keystore" "$PERSISTENT_KEYSTORE"
    fi
    
    # Generate the keystore locally if it does not exist anywhere
    if [ ! -f "$PERSISTENT_KEYSTORE" ]; then
        echo "Generating stable debug keystore..."
        mkdir -p "$HOME/.apkstudio"
        keytool -genkey -v -keystore "$PERSISTENT_KEYSTORE" -storepass android -alias androiddebugkey -keypass android -keyalg RSA -keysize 2048 -validity 10000 -dname "CN=Android Debug,O=Android,C=US"
        # Export a backup copy to your shared Downloads folder immediately
        cp "$PERSISTENT_KEYSTORE" "/sdcard/Download/${APP_NAME}-debug.keystore"
    fi
    
    # Ensure backup copy remains saved alongside the compiled APK
    if [ ! -f "/sdcard/Download/${APP_NAME}-debug.keystore" ]; then
        cp "$PERSISTENT_KEYSTORE" "/sdcard/Download/${APP_NAME}-debug.keystore"
    fi

    apksigner sign --ks "$PERSISTENT_KEYSTORE" --ks-pass pass:android --out "/sdcard/Download/${APP_NAME}-debug.apk" "$BUILD_DIR/apk/app-aligned.apk"
fi

echo "6. Cleaning up staging artifacts..."
rm -rf "$BUILD_DIR"

# Execute project-specific post-build cleanup hook
if [ -f "build_post.sh" ]; then
    echo "Executing project-specific post-build hook..."
    chmod +x build_post.sh
    ./build_post.sh
fi
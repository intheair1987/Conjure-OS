#!/usr/bin/env bash
# ApkStudio Build Script for ApkWrapper

APP_NAME="ApkWrapper"
PKG_NAME="com.conjure.apkwrapper"
PROJ_DIR=$(pwd)
BUILD_DIR="$PROJ_DIR/build"

# Record stdout and stderr of the entire session to a log file
LOG_FILE="$PROJ_DIR/build.log"
rm -f "$LOG_FILE"
exec > >(tee -a "$LOG_FILE") 2>&1

echo "0. Checking dependencies..."
get_package_name() {
    case "$1" in
        zipalign) echo "aapt" ;;
        aapt2) echo "aapt" ;;
        readelf) echo "binutils" ;;
        *) echo "$1" ;;
    esac
}

for cmd in aapt2 ecj d8 apksigner zip readelf zipalign patchelf; do
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

echo "Gathering toolchain into assets and lib..."
mkdir -p assets
cp "$ANDROID_JAR" assets/android.jar

ARCH=$(uname -m)
case "$ARCH" in
    aarch64) ABI="arm64-v8a" ;;
    armv7l|armv8l) ABI="armeabi-v7a" ;;
    x86_64) ABI="x86_64" ;;
    i686) ABI="x86" ;;
    *) ABI="arm64-v8a" ;;
esac
mkdir -p "lib/$ABI"

AAPT2_BIN=$(command -v aapt2)
cp "$AAPT2_BIN" "lib/$ABI/libaapt2.so"

ZIPALIGN_BIN=$(command -v zipalign)
cp "$ZIPALIGN_BIN" "lib/$ABI/libzipalign.so"

gather_libs() {
    local target_bin=$1
    for lib in $(readelf -d "$target_bin" 2>/dev/null | grep NEEDED | awk -F'[' '{print $2}' | awk -F']' '{print $1}'); do
        if [ -f "$PREFIX/lib/$lib" ] && [ ! -f "lib/$ABI/$lib" ]; then
            cp "$PREFIX/lib/$lib" "lib/$ABI/"
            gather_libs "$PREFIX/lib/$lib"
        fi
    done
}
gather_libs "$AAPT2_BIN"
gather_libs "$ZIPALIGN_BIN"

echo "Sanitizing shared libraries: renaming .so.* suffixes and patching ELF dependency mappings..."
cd "lib/$ABI"
for file in *; do
    if [[ "$file" =~ \.so\.[0-9]+ ]]; then
        clean_name=$(echo "$file" | sed -E 's/\.so\.[0-9]+.*/.so/')
        mv "$file" "$clean_name"
    fi
done
for file in *; do
    if [ -f "$file" ]; then
        patchelf --set-soname "$file" "$file" 2>/dev/null
        for dep in $(readelf -d "$file" 2>/dev/null | grep NEEDED | awk -F'[' '{print $2}' | awk -F']' '{print $1}'); do
            if [[ "$dep" =~ \.so\.[0-9]+ ]]; then
                clean_dep=$(echo "$dep" | sed -E 's/\.so\.[0-9]+.*/.so/')
                patchelf --replace-needed "$dep" "$clean_dep" "$file" 2>/dev/null
            fi
        done
    fi
done
cd "$PROJ_DIR"

CACHE_DIR="$HOME/.apkstudio/cache"
mkdir -p "$CACHE_DIR"

gather_dex() {
    JAR_NAME=$1
    OUT_DEX=$2
    if [ -f "$PREFIX/share/dex/$JAR_NAME" ]; then
        cp "$PREFIX/share/dex/$JAR_NAME" "assets/$OUT_DEX"
    elif [ -f "$CACHE_DIR/$OUT_DEX" ]; then
        cp "$CACHE_DIR/$OUT_DEX" "assets/$OUT_DEX"
    elif [ -f "$PREFIX/share/java/$JAR_NAME" ]; then
        d8 --release --min-api 24 --lib "$ANDROID_JAR" --output . "$PREFIX/share/java/$JAR_NAME"
        zip -q "$CACHE_DIR/$OUT_DEX" classes*.dex
        rm classes*.dex
        cp "$CACHE_DIR/$OUT_DEX" "assets/$OUT_DEX"
    elif [ "$JAR_NAME" = "d8.jar" ] && [ -f "$PREFIX/share/dex/r8.jar" ]; then
        cp "$PREFIX/share/dex/r8.jar" "assets/$OUT_DEX"
    elif [ "$JAR_NAME" = "d8.jar" ] && [ -f "$CACHE_DIR/r8_d8.dex" ]; then
        cp "$CACHE_DIR/r8_d8.dex" "assets/$OUT_DEX"
    elif [ "$JAR_NAME" = "d8.jar" ] && [ -f "$PREFIX/share/java/r8.jar" ]; then
        d8 --release --min-api 24 --lib "$ANDROID_JAR" --output . "$PREFIX/share/java/r8.jar"
        zip -q "$CACHE_DIR/r8_d8.dex" classes*.dex
        rm classes*.dex
        cp "$CACHE_DIR/r8_d8.dex" "assets/$OUT_DEX"
    fi
}

gather_dex "ecj.jar" "ecj.jar"
gather_dex "d8.jar" "d8.jar"
gather_dex "apksigner.jar" "apksigner.jar"

PERSISTENT_KEYSTORE="$HOME/.apkstudio/debug.keystore"
if [ ! -f "$PERSISTENT_KEYSTORE" ]; then
    mkdir -p "$HOME/.apkstudio"
    keytool -genkey -v -keystore "$PERSISTENT_KEYSTORE" -storepass android -alias androiddebugkey -keypass android -keyalg RSA -keysize 2048 -validity 10000 -dname "CN=Android Debug,O=Android,C=US"
fi
cp "$PERSISTENT_KEYSTORE" assets/debug.keystore

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
    -A assets/ \
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
zip -ur "$BUILD_DIR/apk/app-unsigned.apk" lib/

echo "4.5 Aligning APK..."
zipalign -f -p 4 "$BUILD_DIR/apk/app-unsigned.apk" "$BUILD_DIR/apk/app-aligned.apk"

echo "5. Signing APK..."
apksigner sign --ks "$PERSISTENT_KEYSTORE" --ks-pass pass:android --out "/sdcard/Download/${APP_NAME}-debug.apk" "$BUILD_DIR/apk/app-aligned.apk"

echo "6. Cleaning up staging artifacts..."
rm -rf "$BUILD_DIR"
rm -rf assets/android.jar assets/ecj.jar assets/d8.jar assets/apksigner.jar assets/debug.keystore
rm -rf lib
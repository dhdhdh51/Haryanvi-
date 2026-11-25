# =====================================================================
#  Haryanvi AI — R8 / ProGuard rules
# =====================================================================

# ---------------------------------------------------------------------
#  kotlinx.serialization
#  The plugin generates serializers that R8 cannot see being used.
# ---------------------------------------------------------------------
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**

-keepclassmembers class kotlinx.serialization.json.** {
    *** Companion;
}
-keepclasseswithmembers class kotlinx.serialization.json.** {
    kotlinx.serialization.KSerializer serializer(...);
}

# Keep the generated serializer for every @Serializable class in the app.
-if @kotlinx.serialization.Serializable class **
-keepclassmembers class <1> {
    static <1>$Companion Companion;
    static kotlinx.serialization.KSerializer serializer(...);
}
-if @kotlinx.serialization.Serializable class **$Companion
-keepclassmembers class <1> {
    kotlinx.serialization.KSerializer serializer(...);
}

# DTOs are reflected over by name during deserialization.
-keep,allowobfuscation,allowshrinking class com.haryanvi.translator.data.remote.dto.** { *; }

# ---------------------------------------------------------------------
#  OkHttp
# ---------------------------------------------------------------------
-dontwarn okhttp3.internal.platform.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# ---------------------------------------------------------------------
#  Room
# ---------------------------------------------------------------------
-keep class * extends androidx.room.RoomDatabase
-dontwarn androidx.room.paging.**

# ---------------------------------------------------------------------
#  Components referenced only from the manifest
# ---------------------------------------------------------------------
-keep class com.haryanvi.translator.keyboard.HaryanviInputMethodService { *; }
-keep class com.haryanvi.translator.floating.FloatingTranslatorService { *; }
-keep class com.haryanvi.translator.MainActivity { *; }
-keep class com.haryanvi.translator.share.ShareTranslateActivity { *; }

# ---------------------------------------------------------------------
#  Keep line numbers so crash reports stay useful, but hide file names.
# ---------------------------------------------------------------------
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile

# kotlinx.serialization — the @Serializable annotation drives codegen; the
# generated companion serializers reflect on their containing class.
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.AnnotationsKt
-keep,includedescriptorclasses class com.vaultkeeper.app.**$$serializer { *; }
-keepclassmembers class com.vaultkeeper.app.** {
    *** Companion;
}
-keepclasseswithmembers class com.vaultkeeper.app.** {
    kotlinx.serialization.KSerializer serializer(...);
}

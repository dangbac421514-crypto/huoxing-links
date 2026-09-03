import java.io.File
import java.io.InputStreamReader
import java.nio.charset.StandardCharsets
import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val localProperties = Properties().also { properties ->
    val localPropertiesFile = rootProject.file("local.properties")
    if (localPropertiesFile.isFile) {
        InputStreamReader(localPropertiesFile.inputStream(), StandardCharsets.UTF_8).use {
            properties.load(it)
        }
    }
}

val keystoreProperties = Properties().also { properties ->
    val configuredPath = System.getenv("JIFENG_KEYSTORE_PROPERTIES")?.takeIf { it.isNotBlank() }
    val keystorePropertiesFile = configuredPath?.let { File(it) }
        ?: File(System.getProperty("user.home"), ".config/jifeng-assistant/keystore.properties")
    if (keystorePropertiesFile.isFile) {
        keystorePropertiesFile.inputStream().use { properties.load(it) }
    }
}

fun buildConfigString(value: String): String = "\"${value
    .replace("\\", "\\\\")
    .replace("\"", "\\\"")
    .replace("\r", "\\r")
    .replace("\n", "\\n")}\""

android {
    namespace = "com.jixingwangluo.jifengassistant"
    compileSdk = 36
    defaultConfig {
        applicationId = "com.jixingwangluo.jifengassistant"
        minSdk = 23
        targetSdk = 36
        versionCode = 1
        versionName = "1.0.0"
        testInstrumentationRunner = "com.jixingwangluo.jifengassistant.JifengTestRunner"
        buildConfigField("String", "DOUYIN_CLIENT_KEY", buildConfigString(localProperties.getProperty("DOUYIN_CLIENT_KEY", "")))
        buildConfigField("String", "DOUYIN_APPROVED_SHARE_URL", buildConfigString(localProperties.getProperty("DOUYIN_APPROVED_SHARE_URL", "")))
        buildConfigField("String", "DOUYIN_CARD_TITLE", buildConfigString(localProperties.getProperty("DOUYIN_CARD_TITLE", "")))
        buildConfigField("String", "DOUYIN_CARD_DESCRIPTION", buildConfigString(localProperties.getProperty("DOUYIN_CARD_DESCRIPTION", "")))
        buildConfigField("String", "DOUYIN_CARD_THUMB_URL", buildConfigString(localProperties.getProperty("DOUYIN_CARD_THUMB_URL", "")))
    }
    buildFeatures { viewBinding = true; buildConfig = true }
    signingConfigs {
        create("release") {
            storeFile = keystoreProperties.getProperty("storeFile")
                ?.takeIf { it.isNotBlank() }
                ?.let { file(it) }
            storePassword = keystoreProperties.getProperty("storePassword")
                ?.takeIf { it.isNotBlank() }
            keyAlias = keystoreProperties.getProperty("keyAlias")
                ?.takeIf { it.isNotBlank() }
            keyPassword = keystoreProperties.getProperty("keyPassword")
                ?.takeIf { it.isNotBlank() }
        }
    }
    buildTypes {
        getByName("release") {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName("release")
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.appcompat)
    implementation(libs.material)
    implementation(libs.lifecycle.runtime.ktx)
    implementation(libs.lifecycle.viewmodel.ktx)
    implementation(libs.coroutines.android)
    implementation(libs.douyin.external)
    implementation(libs.douyin.common)
    testImplementation(libs.junit4)
    testImplementation(libs.coroutines.test)
    androidTestImplementation(libs.androidx.test.junit)
    androidTestImplementation(libs.espresso.core)
}

val validateReleaseInputs = tasks.register("validateReleaseInputs") {
    doLast {
        val requiredInputs = linkedMapOf(
            "DOUYIN_CLIENT_KEY" to localProperties.getProperty("DOUYIN_CLIENT_KEY"),
            "DOUYIN_APPROVED_SHARE_URL" to localProperties.getProperty("DOUYIN_APPROVED_SHARE_URL"),
            "DOUYIN_CARD_TITLE" to localProperties.getProperty("DOUYIN_CARD_TITLE"),
            "DOUYIN_CARD_DESCRIPTION" to localProperties.getProperty("DOUYIN_CARD_DESCRIPTION"),
            "storeFile" to keystoreProperties.getProperty("storeFile"),
            "storePassword" to keystoreProperties.getProperty("storePassword"),
            "keyAlias" to keystoreProperties.getProperty("keyAlias"),
            "keyPassword" to keystoreProperties.getProperty("keyPassword"),
        )
        val missingInputs = requiredInputs.filterValues { it.isNullOrBlank() }.keys
        if (missingInputs.isNotEmpty()) {
            throw GradleException("Missing required release inputs: ${missingInputs.joinToString(", ")}")
        }

        val approvedUrl = requiredInputs.getValue("DOUYIN_APPROVED_SHARE_URL")!!
        if (!approvedUrl.startsWith("https://")) {
            throw GradleException("DOUYIN_APPROVED_SHARE_URL must use HTTPS")
        }
        val releaseKeystore = File(requiredInputs.getValue("storeFile")!!)
        if (!releaseKeystore.isFile) {
            throw GradleException("Release keystore does not exist")
        }
    }
}

tasks.configureEach {
    if (name == "preReleaseBuild") {
        dependsOn(validateReleaseInputs)
    }
}

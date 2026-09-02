plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "com.jixingwangluo.jifengassistant"
    compileSdk = 36
    defaultConfig {
        applicationId = "com.jixingwangluo.jifengassistant"
        minSdk = 23
        targetSdk = 36
        versionCode = 1
        versionName = "1.0.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }
    buildFeatures { viewBinding = true; buildConfig = true }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}

// AndroidX 1.19.0 requires AGP 9.1/API 37, while this shell is intentionally
// pinned to the API 36 and AGP 8.13 baseline from the approved design.
configurations.configureEach {
    resolutionStrategy.force(
        "androidx.core:core:1.16.0",
        "androidx.core:core-ktx:1.16.0",
    )
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

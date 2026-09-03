package com.jixingwangluo.jifengassistant.douyin

import org.junit.Assert.assertEquals
import org.junit.Test

class ShareResultMapperTest {
    @Test
    fun mapsSuccessfulAndCancelledCallbacks() {
        assertEquals(ShareResult.Success, ShareResultMapper.from(20000, false, null))
        assertEquals(ShareResult.Cancelled, ShareResultMapper.from(20000, true, "cancelled"))
        assertEquals(ShareResult.Cancelled, ShareResultMapper.from(20004, false, "cancelled"))
        assertEquals(ShareResult.Cancelled, ShareResultMapper.from(20013, true, "user cancelled"))
    }

    @Test
    fun mapsPermissionNetworkAndApprovalFailures() {
        assertEquals(
            ShareResult.PermissionOrPackageMismatch(20003, "package mismatch"),
            ShareResultMapper.from(20003, false, "package mismatch"),
        )
        assertEquals(
            ShareResult.NetworkError(20006, "network unavailable"),
            ShareResultMapper.from(20006, false, "network unavailable"),
        )
        assertEquals(
            ShareResult.UrlNotApproved(20017, "url not approved"),
            ShareResultMapper.from(20017, false, "url not approved"),
        )
    }

    @Test
    fun mapsUnrecognizedCodeAndSanitizesRetainedMessage() {
        val message = "first\u0000line\nsecond\t" + "x".repeat(250)
        val result = ShareResultMapper.from(29999, false, message)

        assertEquals(
            ShareResult.Unknown(29999, "firstlinesecond" + "x".repeat(200 - "firstlinesecond".length)),
            result,
        )
    }
}

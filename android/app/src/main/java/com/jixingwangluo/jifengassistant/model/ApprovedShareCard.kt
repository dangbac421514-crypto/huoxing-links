package com.jixingwangluo.jifengassistant.model

import java.net.URI

data class ApprovedShareCard private constructor(
    val url: String,
    val title: String,
    val description: String,
    val thumbUrl: String?,
) {
    companion object {
        fun create(
            url: String,
            title: String,
            description: String,
            thumbUrl: String?,
        ): ApprovedShareCard {
            validateUrl(url, "url")
            require(title.isNotBlank()) { "title must not be blank" }
            require(description.isNotBlank()) { "description must not be blank" }

            val normalizedThumbUrl = thumbUrl?.takeIf { it.isNotBlank() }
            normalizedThumbUrl?.let { validateUrl(it, "thumbUrl") }

            return ApprovedShareCard(url, title, description, normalizedThumbUrl)
        }

        private fun validateUrl(value: String, field: String) {
            val uri = try {
                URI(value)
            } catch (exception: Exception) {
                throw IllegalArgumentException("$field must be a valid URI", exception)
            }

            require(uri.scheme.equals("https", ignoreCase = true)) {
                "$field must use HTTPS"
            }
            require(!uri.host.isNullOrBlank()) { "$field must include a host" }
            require(uri.userInfo == null) { "$field must not include user info" }
            require(uri.fragment == null) { "$field must not include a fragment" }
        }
    }
}

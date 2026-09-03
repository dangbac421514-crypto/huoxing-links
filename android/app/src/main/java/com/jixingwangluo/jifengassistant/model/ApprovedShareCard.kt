package com.jixingwangluo.jifengassistant.model

import java.net.URI
import java.net.URISyntaxException

class ApprovedShareCard(
    val url: String,
    val title: String,
    val description: String,
    thumbUrl: String?,
) {
    val thumbUrl: String? = thumbUrl?.takeIf { it.isNotBlank() }

    init {
        validateUrl(url, "url")
        require(title.isNotBlank()) { "title must not be blank" }
        require(description.isNotBlank()) { "description must not be blank" }
        this.thumbUrl?.let { validateUrl(it, "thumbUrl") }
    }

    companion object {
        fun create(
            url: String,
            title: String,
            description: String,
            thumbUrl: String?,
        ): ApprovedShareCard = ApprovedShareCard(url, title, description, thumbUrl)

        private fun validateUrl(value: String, field: String) {
            val uri = try {
                URI(value)
            } catch (exception: URISyntaxException) {
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

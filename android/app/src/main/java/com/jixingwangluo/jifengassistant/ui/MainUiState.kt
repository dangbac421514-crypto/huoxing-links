package com.jixingwangluo.jifengassistant.ui

import com.jixingwangluo.jifengassistant.model.ApprovedShareCard

data class MainUiState(
    val card: ApprovedShareCard,
    val privacyAccepted: Boolean,
    val inProgress: Boolean = false,
    val statusText: String = "",
) {
    val shareEnabled: Boolean get() = privacyAccepted && !inProgress
}

package com.jixingwangluo.jifengassistant

import android.content.Intent
import android.content.Context
import android.net.Uri
import android.os.Bundle
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import com.jixingwangluo.jifengassistant.databinding.ActivityMainBinding
import com.jixingwangluo.jifengassistant.douyin.ShareLaunchResult
import com.jixingwangluo.jifengassistant.ui.MainUiState
import com.jixingwangluo.jifengassistant.ui.MainViewModel

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding
    private lateinit var graph: AppGraph
    private var viewModel: MainViewModel? = null
    private var privacyDialog: AlertDialog? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        graph = testGraphFactory?.invoke(applicationContext)
            ?: (application as JifengApplication).appGraph
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)
        renderCard()
        bindActions()
        handleCallbackIntent(intent)
        if (!graph.privacyConsentStore.isAccepted()) {
            binding.root.post { showPrivacyConsent() }
        }
    }

    override fun onNewIntent(intent: Intent?) {
        super.onNewIntent(intent)
        if (intent == null) return
        setIntent(intent)
        handleCallbackIntent(intent)
    }

    private fun renderCard() {
        val card = graph.card
        binding.appIcon.setImageResource(R.drawable.jifeng_assistant_icon)
        if (card == null) {
            binding.cardTitle.text = getString(R.string.card_unavailable_title)
            binding.cardDescription.text = getString(R.string.card_unavailable_description)
            binding.approvedDomain.text = getString(R.string.approved_domain_unavailable)
            binding.statusText.text = graph.configurationError ?: getString(R.string.card_unavailable_status)
            binding.shareButton.isEnabled = false
            return
        }
        binding.cardTitle.text = card.title
        binding.cardDescription.text = card.description
        binding.approvedDomain.text = Uri.parse(card.url).host.orEmpty()
        viewModel = MainViewModel(card, graph.privacyConsentStore.isAccepted())
        graph.configurationError?.let { binding.statusText.text = it }
        renderState(viewModel?.state?.value)
    }

    private fun bindActions() {
        binding.privacyAction.setOnClickListener { showPrivacyConsent() }
        binding.shareButton.setOnClickListener {
            val model = viewModel ?: return@setOnClickListener
            if (!graph.privacyConsentStore.isAccepted()) {
                showPrivacyConsent()
                return@setOnClickListener
            }
            if (!model.onShareRequested()) return@setOnClickListener
            val result = runCatching {
                graph.createShareGateway(this).launch(this, model.state.value.card)
            }.getOrElse { ShareLaunchResult.SdkRejectedRequest }
            model.onShareLaunchResult(result)
            renderState(model.state.value)
        }
    }

    private fun showPrivacyConsent() {
        if (isFinishing || privacyDialog?.isShowing == true) return
        privacyDialog = AlertDialog.Builder(this)
            .setTitle(R.string.privacy_dialog_title)
            .setMessage(R.string.privacy_dialog_message)
            .setNegativeButton(R.string.privacy_decline) { _, _ ->
                viewModel?.onPrivacyDeclined()
                viewModel?.let { renderState(it.state.value) }
            }
            .setPositiveButton(R.string.privacy_accept) { _, _ -> acceptPrivacy() }
            .create()
            .also {
                it.setOnDismissListener { privacyDialog = null }
                it.show()
            }
    }

    private fun acceptPrivacy() {
        if (!graph.privacyConsentStore.isAccepted()) {
            graph.privacyConsentStore.setAccepted()
            runCatching { graph.initializer.onPrivacyAccepted() }
                .onFailure { graph.configurationError = "抖音应用配置不可用，当前无法分享" }
            viewModel?.onPrivacyAccepted()
        }
        viewModel?.let { renderState(it.state.value) }
    }

    private fun handleCallbackIntent(intent: Intent?) {
        if (intent == null) {
            return
        }
        val resultExtra = com.jixingwangluo.jifengassistant.douyin.DouYinEntryActivity.EXTRA_RESULT_TYPE
        val errorCodeExtra = com.jixingwangluo.jifengassistant.douyin.DouYinEntryActivity.EXTRA_ERROR_CODE
        val errorMessageExtra = com.jixingwangluo.jifengassistant.douyin.DouYinEntryActivity.EXTRA_ERROR_MESSAGE
        val hasResult = intent.hasExtra(resultExtra)
        val type = intent.getStringExtra(resultExtra)
        val code = if (intent.hasExtra(com.jixingwangluo.jifengassistant.douyin.DouYinEntryActivity.EXTRA_ERROR_CODE)) {
            intent.getIntExtra(com.jixingwangluo.jifengassistant.douyin.DouYinEntryActivity.EXTRA_ERROR_CODE, 0)
        } else {
            null
        }
        val message = intent.getStringExtra(errorMessageExtra)
        intent.removeExtra(resultExtra)
        intent.removeExtra(errorCodeExtra)
        intent.removeExtra(errorMessageExtra)
        val model = viewModel ?: return
        if (!hasResult) return
        model.onCallback(type, code, message)
        renderState(model.state.value)
    }

    private fun renderState(state: MainUiState?) {
        if (state == null) return
        binding.shareButton.isEnabled = state.shareEnabled && graph.configurationError == null
        if (state.statusText.isNotBlank()) {
            binding.statusText.text = state.statusText
        } else if (graph.configurationError != null) {
            binding.statusText.text = graph.configurationError
        } else if (!state.privacyAccepted) {
            binding.statusText.text = getString(R.string.privacy_required_status)
        } else {
            binding.statusText.text = getString(R.string.ready_status)
        }
    }

    companion object {
        /** Instrumentation-only activity graph override; null in production. */
        @JvmField
        var testGraphFactory: ((Context) -> AppGraph)? = null
    }

}

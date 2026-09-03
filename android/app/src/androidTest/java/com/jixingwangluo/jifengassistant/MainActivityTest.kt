package com.jixingwangluo.jifengassistant

import androidx.test.core.app.ActivityScenario
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.espresso.Espresso.onView
import androidx.test.espresso.assertion.ViewAssertions.doesNotExist
import androidx.test.espresso.assertion.ViewAssertions.matches
import androidx.test.espresso.matcher.ViewMatchers.isDisplayed
import androidx.test.espresso.matcher.ViewMatchers.isEnabled
import androidx.test.espresso.matcher.ViewMatchers.withId
import androidx.test.espresso.matcher.ViewMatchers.withText
import com.jixingwangluo.jifengassistant.douyin.ContactShareClient
import com.jixingwangluo.jifengassistant.douyin.DouyinShareGateway
import com.jixingwangluo.jifengassistant.douyin.InMemoryPendingShareStore
import com.jixingwangluo.jifengassistant.model.ApprovedShareCard
import com.jixingwangluo.jifengassistant.privacy.PrivacyConsentStore
import org.hamcrest.CoreMatchers.not
import org.junit.After
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class MainActivityTest {
    private lateinit var store: FakePrivacyStore
    private lateinit var initializer: RecordingInitializer

    @Before
    fun installTestGraph() {
        store = FakePrivacyStore(false)
        initializer = RecordingInitializer()
        MainActivity.testGraphFactory = { context ->
            AppGraph.forTesting(
                card = ApprovedShareCard.create(
                    "https://link.bjaajsdad.xyz/douyin/jifeng-assistant",
                    "极风小助手",
                    "已审核链接的管理与分享工具",
                    null,
                ),
                privacyConsentStore = store,
                initializer = initializer,
                gateway = DouyinShareGateway(FakeClient(), InMemoryPendingShareStore()),
            )
        }
    }

    @After
    fun removeTestGraph() {
        MainActivity.testGraphFactory = null
    }

    @Test
    fun fixedCardAndPrivacyGateAreVisible() {
        ActivityScenario.launch(MainActivity::class.java).use {
            onView(withText("不同意")).check(matches(isDisplayed()))
            onView(withText("同意并继续")).check(matches(isDisplayed()))
            onView(withText("不同意")).perform(androidx.test.espresso.action.ViewActions.click())
            onView(withId(R.id.app_icon)).check(matches(isDisplayed()))
            onView(withId(R.id.app_name)).check(matches(withText("极风小助手")))
            onView(withId(R.id.card_title)).check(matches(withText("极风小助手")))
            onView(withId(R.id.card_description)).check(matches(withText("已审核链接的管理与分享工具")))
            onView(withId(R.id.approved_domain)).check(matches(withText("link.bjaajsdad.xyz")))
            onView(withId(R.id.share_button)).check(matches(isDisplayed()))
            onView(withId(R.id.share_button)).check(matches(not(isEnabled())))
            listOf(
                "\u6ce8\u518c",
                "\u767b\u5f55",
                "\u4f1a\u5458",
                "\u652f\u4ed8",
                "\u4e8c\u7ef4\u7801",
            ).forEach { forbidden ->
                onView(withText(forbidden)).check(doesNotExist())
            }
        }
    }

    @Test
    fun acceptingPrivacyPersistsAndInitializesExactlyOnce() {
        ActivityScenario.launch(MainActivity::class.java).use {
            onView(withText("同意并继续")).perform(androidx.test.espresso.action.ViewActions.click())
            assert(store.accepted)
            assert(initializer.acceptedCalls == 1)
            onView(withId(R.id.share_button)).check(matches(isEnabled()))
        }
    }

    private class FakePrivacyStore(initial: Boolean) : PrivacyConsentStore {
        var accepted = initial

        override fun isAccepted(): Boolean = accepted

        override fun setAccepted() {
            accepted = true
        }
    }

    private class RecordingInitializer : PrivacyInitializer {
        var acceptedCalls = 0

        override fun initialize() = Unit

        override fun onPrivacyAccepted() {
            acceptedCalls += 1
        }
    }

    private class FakeClient : ContactShareClient {
        override fun isInstalled() = false

        override fun supportsContactShare() = false

        override fun launch(card: ApprovedShareCard, state: String, callbackClassName: String) = false
    }
}

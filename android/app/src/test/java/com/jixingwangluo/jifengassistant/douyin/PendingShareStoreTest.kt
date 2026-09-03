package com.jixingwangluo.jifengassistant.douyin

import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class PendingShareStoreTest {
    @Test
    fun savesAndConsumesMatchingState() {
        val store = InMemoryPendingShareStore()

        store.save("state-1")

        assertTrue(store.consumeIfMatches("state-1"))
        assertNull(store.peek())
    }

    @Test
    fun mismatchRetainsPendingState() {
        val store = InMemoryPendingShareStore()
        store.save("state-1")

        assertFalse(store.consumeIfMatches("state-2"))
        assertTrue(store.peek() == "state-1")
    }

    @Test
    fun matchingStateCanOnlyBeConsumedOnce() {
        val store = InMemoryPendingShareStore()
        store.save("state-1")

        assertTrue(store.consumeIfMatches("state-1"))
        assertFalse(store.consumeIfMatches("state-1"))
    }
}

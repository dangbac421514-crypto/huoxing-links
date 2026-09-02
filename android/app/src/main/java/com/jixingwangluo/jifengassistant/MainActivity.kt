package com.jixingwangluo.jifengassistant

import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import com.jixingwangluo.jifengassistant.databinding.ActivityMainBinding

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(ActivityMainBinding.inflate(layoutInflater).root)
    }
}

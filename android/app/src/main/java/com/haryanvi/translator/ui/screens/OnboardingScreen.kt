package com.haryanvi.translator.ui.screens

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.haryanvi.translator.R
import kotlinx.coroutines.launch

/**
 * Four-page introduction. The last page is about privacy, because telling the
 * user what the app will not do is the most important promise to make before
 * they grant a keyboard or overlay permission.
 */
@Composable
fun OnboardingScreen(
    onFinished: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val pages = listOf(
        OnboardingPage("✍", R.string.onboarding_1_title, R.string.onboarding_1_body),
        OnboardingPage("ह", R.string.onboarding_2_title, R.string.onboarding_2_body),
        OnboardingPage("⌨", R.string.onboarding_3_title, R.string.onboarding_3_body),
        OnboardingPage("🔒", R.string.onboarding_4_title, R.string.onboarding_4_body),
    )

    val pagerState = rememberPagerState(pageCount = { pages.size })
    val scope = rememberCoroutineScope()

    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(horizontal = 24.dp, vertical = 20.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.End,
        ) {
            if (pagerState.currentPage < pages.lastIndex) {
                TextButton(onClick = onFinished) {
                    Text(stringResource(R.string.onboarding_skip))
                }
            }
        }

        HorizontalPager(
            state = pagerState,
            modifier = Modifier.weight(1f),
        ) { index ->
            val page = pages[index]

            Column(
                modifier = Modifier.fillMaxSize(),
                verticalArrangement = Arrangement.Center,
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Surface(
                    shape = RoundedCornerShape(28.dp),
                    color = MaterialTheme.colorScheme.surfaceVariant,
                    modifier = Modifier.size(110.dp),
                ) {
                    Box(contentAlignment = Alignment.Center) {
                        Text(
                            text = page.mark,
                            style = MaterialTheme.typography.displaySmall,
                            color = MaterialTheme.colorScheme.primary,
                        )
                    }
                }

                Spacer(Modifier.height(36.dp))

                Text(
                    text = stringResource(page.titleRes),
                    style = MaterialTheme.typography.headlineSmall,
                    textAlign = TextAlign.Center,
                    color = MaterialTheme.colorScheme.onBackground,
                )

                Spacer(Modifier.height(12.dp))

                Text(
                    text = stringResource(page.bodyRes),
                    style = MaterialTheme.typography.bodyLarge,
                    textAlign = TextAlign.Center,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(horizontal = 8.dp),
                )
            }
        }

        /* ---- Page indicator ----------------------------------------- */
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(vertical = 20.dp),
            horizontalArrangement = Arrangement.Center,
        ) {
            pages.indices.forEach { index ->
                val selected = pagerState.currentPage == index
                val width by animateFloatAsState(
                    targetValue = if (selected) 26f else 8f,
                    label = "indicator",
                )

                Box(
                    modifier = Modifier
                        .padding(horizontal = 3.dp)
                        .height(8.dp)
                        .width(width.dp)
                        .clip(CircleShape)
                        .background(
                            if (selected) {
                                MaterialTheme.colorScheme.primary
                            } else {
                                MaterialTheme.colorScheme.outline
                            }
                        ),
                )
            }
        }

        Button(
            onClick = {
                if (pagerState.currentPage == pages.lastIndex) {
                    onFinished()
                } else {
                    scope.launch { pagerState.animateScrollToPage(pagerState.currentPage + 1) }
                }
            },
            modifier = Modifier
                .fillMaxWidth()
                .height(52.dp),
            shape = RoundedCornerShape(14.dp),
        ) {
            Text(
                text = if (pagerState.currentPage == pages.lastIndex) {
                    stringResource(R.string.onboarding_start)
                } else {
                    stringResource(R.string.onboarding_next)
                },
                style = MaterialTheme.typography.titleMedium,
            )
        }
    }
}

private data class OnboardingPage(
    val mark: String,
    val titleRes: Int,
    val bodyRes: Int,
)

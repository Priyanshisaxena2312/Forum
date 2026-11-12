<?php
/**
 * AI Conversation Insights Panel Component
 * Included in topic.php and debate.php
 * Expects $ai_discussion_type ('topic' or 'debate') and $ai_discussion_id (int)
 */

if (!isset($ai_discussion_type) || !isset($ai_discussion_id)) {
    return;
}

require_once __DIR__ . '/ai_insights_service.php';

$cached_insight = get_cached_insights($pdo, $ai_discussion_type, $ai_discussion_id);
$has_insights = !empty($cached_insight);
?>

<!-- AI Conversation Insights Component -->
<div class="ai-insights-container" id="aiInsightsContainer" data-type="<?= htmlspecialchars($ai_discussion_type) ?>" data-id="<?= (int)$ai_discussion_id ?>">

    <!-- Top Trigger Banner (Visible when no insights generated yet) -->
    <div class="ai-trigger-banner <?= $has_insights ? 'has-insights-hidden' : '' ?>" id="aiTriggerBanner">
        <div class="ai-trigger-content">
            <div class="ai-sparkle-badge">
                <i class="fas fa-wand-magic-sparkles"></i>
            </div>
            <div class="ai-trigger-text">
                <div class="ai-trigger-title">AI Conversation Insights</div>
                <div class="ai-trigger-desc">Instant executive summary, key arguments for & against, shared consensus, and open questions.</div>
            </div>
        </div>
        <button class="btn btn-ai-sparkle" id="btnAnalyzeDiscussion" data-type="<?= htmlspecialchars($ai_discussion_type) ?>" data-id="<?= (int)$ai_discussion_id ?>">
            <i class="fas fa-wand-magic-sparkles"></i> <span>Analyze Discussion</span>
        </button>
    </div>

    <!-- AI Insights Main Card Panel -->
    <div class="ai-insights-panel <?= $has_insights ? 'active' : '' ?>" id="aiInsightsPanel" style="<?= $has_insights ? '' : 'display: none;' ?>">
        
        <!-- Header -->
        <div class="ai-panel-header">
            <div class="ai-panel-title-wrap">
                <div class="ai-panel-icon">
                    <i class="fas fa-brain"></i>
                </div>
                <div>
                    <h3 class="ai-panel-title">AI Conversation Insights</h3>
                    <div class="ai-panel-meta">
                        <span class="ai-model-badge" id="aiModelBadge">
                            <i class="fas fa-microchip"></i> <span id="aiModelName"><?= htmlspecialchars($cached_insight['model_used'] ?? 'Gemini 1.5 Flash') ?></span>
                        </span>
                        <span class="ai-status-badge" id="aiStatusBadge">
                            <i class="fas fa-database"></i> <span id="aiStatusText"><?= $has_insights ? ('Stored • ' . time_ago($cached_insight['updated_at'])) : 'Freshly Generated' ?></span>
                        </span>
                        <?php if ($has_insights && !empty($cached_insight['post_count_analyzed'])): ?>
                            <span class="ai-count-badge" id="aiCountBadge">
                                <i class="fas fa-comments"></i> <?= (int)$cached_insight['post_count_analyzed'] ?> contributions
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ai-panel-actions">
                <button class="btn btn-secondary btn-sm" id="btnReanalyzeDiscussion" title="Re-run AI analysis with latest posts" data-type="<?= htmlspecialchars($ai_discussion_type) ?>" data-id="<?= (int)$ai_discussion_id ?>">
                    <i class="fas fa-arrows-rotate"></i> <span>Re-analyze</span>
                </button>
                <button class="btn btn-secondary btn-sm" id="btnToggleInsightsCollapse" title="Collapse or expand panel">
                    <i class="fas fa-chevron-up"></i>
                </button>
            </div>
        </div>

        <!-- Loading State (shown during API request) -->
        <div class="ai-loading-state" id="aiLoadingState" style="display: none;">
            <div class="ai-pulse-orb">
                <i class="fas fa-wand-magic-sparkles"></i>
            </div>
            <h4 class="ai-loading-title">AI Agent is reading this discussion...</h4>
            <p class="ai-loading-subtitle">Synthesizing arguments, consensus points, and open questions across all posts.</p>
            <div class="ai-loading-progress-bar">
                <div class="ai-loading-progress-fill"></div>
            </div>
        </div>

        <!-- Body / Content -->
        <div class="ai-panel-body" id="aiPanelBody">
            
            <!-- 1. Executive Summary -->
            <div class="ai-section ai-summary-section">
                <div class="ai-section-title">
                    <i class="fas fa-quote-left"></i> Summary of the Thread
                </div>
                <div class="ai-summary-text" id="aiSummaryText">
                    <?= !empty($cached_insight['summary']) ? nl2br(htmlspecialchars($cached_insight['summary'])) : '' ?>
                </div>
            </div>

            <!-- 2. Main Arguments Grid (For & Against) -->
            <div class="ai-arguments-grid">
                <!-- Arguments FOR -->
                <div class="ai-arg-column ai-arg-for">
                    <div class="ai-arg-column-header">
                        <span class="ai-arg-icon for-icon"><i class="fas fa-thumbs-up"></i></span>
                        <div class="ai-arg-col-title">Main Arguments For</div>
                    </div>
                    <ul class="ai-arg-list" id="aiArgsForList">
                        <?php if ($has_insights && !empty($cached_insight['arguments_for'])): ?>
                            <?php foreach ($cached_insight['arguments_for'] as $item): ?>
                                <li>
                                    <i class="fas fa-check"></i>
                                    <span><?= htmlspecialchars($item) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Arguments AGAINST -->
                <div class="ai-arg-column ai-arg-against">
                    <div class="ai-arg-column-header">
                        <span class="ai-arg-icon against-icon"><i class="fas fa-thumbs-down"></i></span>
                        <div class="ai-arg-col-title">Main Arguments Against</div>
                    </div>
                    <ul class="ai-arg-list" id="aiArgsAgainstList">
                        <?php if ($has_insights && !empty($cached_insight['arguments_against'])): ?>
                            <?php foreach ($cached_insight['arguments_against'] as $item): ?>
                                <li>
                                    <i class="fas fa-xmark"></i>
                                    <span><?= htmlspecialchars($item) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- 3. Bottom Row: Common Ground & Unresolved Questions -->
            <div class="ai-synthesis-grid">
                <!-- Common Ground -->
                <div class="ai-synthesis-card ai-card-commonground">
                    <div class="ai-synthesis-header">
                        <div class="ai-synth-icon commonground-icon"><i class="fas fa-handshake"></i></div>
                        <div>
                            <div class="ai-synth-title">Common Ground Between Participants</div>
                            <div class="ai-synth-subtitle">Shared values, mutual agreements, and consensus</div>
                        </div>
                    </div>
                    <ul class="ai-synth-list" id="aiCommonGroundList">
                        <?php if ($has_insights && !empty($cached_insight['common_ground'])): ?>
                            <?php foreach ($cached_insight['common_ground'] as $item): ?>
                                <li>
                                    <i class="fas fa-circle-dot"></i>
                                    <span><?= htmlspecialchars($item) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Unresolved Questions & Disagreements -->
                <div class="ai-synthesis-card ai-card-unresolved">
                    <div class="ai-synthesis-header">
                        <div class="ai-synth-icon unresolved-icon"><i class="fas fa-circle-question"></i></div>
                        <div>
                            <div class="ai-synth-title">Unresolved Questions & Disagreements</div>
                            <div class="ai-synth-subtitle">Open tensions, unanswered questions, and future inquiries</div>
                        </div>
                    </div>
                    <ul class="ai-synth-list" id="aiUnresolvedList">
                        <?php if ($has_insights && !empty($cached_insight['unresolved_questions'])): ?>
                            <?php foreach ($cached_insight['unresolved_questions'] as $item): ?>
                                <li>
                                    <i class="fas fa-arrow-right"></i>
                                    <span><?= htmlspecialchars($item) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

        </div>

        <!-- Feedback / Notification banner -->
        <div class="ai-panel-notice" id="aiPanelNotice" style="display: none;"></div>
    </div>
</div>

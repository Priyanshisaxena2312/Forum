document.addEventListener('DOMContentLoaded', () => {
    // ==============================
    // 1. Live search
    // ==============================
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    
    if (searchInput) {
        let timeout = null;
        searchInput.addEventListener('input', (e) => {
            clearTimeout(timeout);
            const query = e.target.value.trim();
            
            if (query.length < 2) {
                searchResults.classList.remove('active');
                searchResults.innerHTML = '';
                return;
            }
            
            timeout = setTimeout(() => {
                fetch(`search_ajax.php?q=${encodeURIComponent(query)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.length > 0) {
                            searchResults.innerHTML = data.map(item => `
                                <a href="topic.php?id=${item.id}" class="search-result-item">
                                    <div style="font-weight: 500;">${item.title}</div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">in ${item.category_name}</div>
                                </a>
                            `).join('');
                            searchResults.classList.add('active');
                        } else {
                            searchResults.innerHTML = '<div style="padding: 10px; color: var(--text-muted); text-align:center;">No results found.</div>';
                            searchResults.classList.add('active');
                        }
                    })
                    .catch(err => console.error('Search error:', err));
            }, 300);
        });
        
        // Hide search results on click outside
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove('active');
            }
        });
    }

    // ==============================
    // 2. Like button
    // ==============================
    const likeBtns = document.querySelectorAll('.like-btn');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    
    likeBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            if (btn.hasAttribute('disabled')) return;
            
            const postId = btn.dataset.id;
            
            fetch('like_post.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    post_id: postId,
                    csrf_token: csrfToken
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const countSpan = btn.querySelector('.like-count');
                    const icon = btn.querySelector('i');
                    countSpan.textContent = data.count;
                    
                    if (data.liked) {
                        btn.style.border = '1px solid var(--accent-rose)';
                        btn.style.color = 'var(--accent-rose)';
                        btn.style.background = 'transparent';
                    } else {
                        btn.style.border = '1px solid transparent';
                        btn.style.color = 'var(--text-secondary)';
                        btn.style.background = 'rgba(255,255,255,0.06)';
                    }
                } else if (data.error) {
                    alert(data.error);
                }
            })
            .catch(err => console.error('Like error:', err));
        });
    });

    // ==============================
    // 3. Reply form Quote
    // ==============================
    const quoteBtns = document.querySelectorAll('.quote-btn');
    const replyContent = document.getElementById('replyContent');
    const replySection = document.getElementById('replySection');
    
    quoteBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const username = btn.dataset.username;
            const content = btn.dataset.content;
            
            if (replyContent) {
                const quoteText = `> @${username} said:\n> ${content}\n\n`;
                replyContent.value += quoteText;
                replyContent.focus();
                
                if (replySection) {
                    replySection.scrollIntoView({ behavior: 'smooth' });
                }
                
                // Trigger char count update
                const event = new Event('input');
                replyContent.dispatchEvent(event);
            }
        });
    });

    // ==============================
    // 4. Character counter
    // ==============================
    const textareas = document.querySelectorAll('textarea');
    textareas.forEach(ta => {
        const countDisplay = ta.nextElementSibling;
        if (countDisplay && countDisplay.id === 'charCount') {
            ta.addEventListener('input', () => {
                const len = ta.value.length;
                countDisplay.textContent = `${len} character${len !== 1 ? 's' : ''}`;
                if (len > 5000) {
                    countDisplay.style.color = 'var(--danger)';
                } else {
                    countDisplay.style.color = 'var(--text-muted)';
                }
            });
            // trigger initially
            const ev = new Event('input');
            ta.dispatchEvent(ev);
        }
    });

    // ==============================
    // 5. Confirm dialogs for admin or any delete action
    // ==============================
    const confirmForms = document.querySelectorAll('form.require-confirm');
    confirmForms.forEach(form => {
        form.addEventListener('submit', (e) => {
            if (!confirm('Are you sure you want to proceed with this action? This cannot be undone.')) {
                e.preventDefault();
            }
        });
    });

    // ==============================
    // 6. Auto-hide flash messages
    // ==============================
    const flashMessages = document.querySelectorAll('.flash-message');
    flashMessages.forEach(msg => {
        setTimeout(() => {
            msg.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out';
            msg.style.opacity = '0';
            msg.style.transform = 'translateY(-8px)';
            setTimeout(() => msg.remove(), 500);
        }, 4000);
    });

    // ==============================
    // 7. Universal & Post Report Modal
    // ==============================
    const reportBtns = document.querySelectorAll('.report-btn, .report-item-btn');
    const reportModal = document.getElementById('reportModal') || document.getElementById('universalReportModal');
    const closeReportModal = document.getElementById('closeReportModal') || document.getElementById('closeUniversalReportModal');
    const submitReport = document.getElementById('submitReport') || document.getElementById('submitUniversalReport');
    const reportPostId = document.getElementById('reportPostId') || document.getElementById('reportTargetId');
    const reportTargetType = document.getElementById('reportTargetType');
    const reportReasonSelect = document.getElementById('reportReasonSelect') || document.getElementById('reportReasonCategory');
    const reportDetails = document.getElementById('reportDetails') || document.getElementById('reportReasonDetails');
    const reportModalTitle = document.getElementById('reportModalTitle');

    if (reportModal && reportBtns.length > 0) {
        reportBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const type = btn.dataset.type || 'post';
                const id = btn.dataset.id || btn.dataset.postId;
                
                if (reportTargetType) reportTargetType.value = type;
                if (reportPostId) reportPostId.value = id;
                if (reportModalTitle) {
                    reportModalTitle.innerHTML = `<i class="fas fa-flag" style="color:var(--accent-rose);"></i> Report ${type.charAt(0).toUpperCase() + type.slice(1)}`;
                }
                reportModal.classList.add('active');
            });
        });

        if (closeReportModal) {
            closeReportModal.addEventListener('click', () => {
                reportModal.classList.remove('active');
            });
        }

        // Close on background click
        reportModal.addEventListener('click', (e) => {
            if (e.target === reportModal) {
                reportModal.classList.remove('active');
            }
        });

        if (submitReport) {
            submitReport.addEventListener('click', () => {
                const targetId = reportPostId ? reportPostId.value : 0;
                const targetType = reportTargetType ? reportTargetType.value : 'post';
                const reason = reportReasonSelect ? reportReasonSelect.value : 'Report';
                const details = reportDetails ? reportDetails.value.trim() : '';
                const fullReason = details ? `${reason}: ${details}` : reason;

                if (!targetId) {
                    alert('Invalid report target.');
                    return;
                }

                fetch('report_item.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        item_type: targetType,
                        item_id: targetId,
                        reason: fullReason,
                        csrf_token: csrfToken
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message || 'Report submitted successfully. Thank you for keeping our community clean.');
                        reportModal.classList.remove('active');
                        if (reportDetails) reportDetails.value = '';
                    } else {
                        alert('Error submitting report: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error('Report error:', err);
                    alert('Failed to submit report. Please check your network connection.');
                });
            });
        }
    }

    // ==============================
    // 8. Composer — redirect to new_topic
    // ==============================
    const composerInput = document.getElementById('composerInput');
    const composerBtn = document.getElementById('composerBtn');

    if (composerInput) {
        composerInput.addEventListener('click', () => {
            window.location.href = 'new_topic.php';
        });
    }

    if (composerBtn) {
        composerBtn.addEventListener('click', () => {
            window.location.href = 'new_topic.php';
        });
    }

    // ==============================
    // 9. Feed Filter Tabs
    // ==============================
    const feedTabs = document.getElementById('feedTabs');
    if (feedTabs) {
        const tabs = feedTabs.querySelectorAll('.feed-tab');
        const feedCards = document.querySelectorAll('.feed-card');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Update active state
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');

                const filter = tab.dataset.filter;

                // Animate cards
                feedCards.forEach((card, i) => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(8px)';
                    setTimeout(() => {
                        card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, i * 40);
                });
            });
        });
    }

    // ==============================
    // 10. Staggered card entrance animation
    // ==============================
    const feedCards = document.querySelectorAll('.feed-card');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, i) => {
            if (entry.isIntersecting) {
                setTimeout(() => {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }, i * 60);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });

    feedCards.forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(12px)';
        card.style.transition = 'opacity 0.4s ease-out, transform 0.4s ease-out';
        observer.observe(card);
    });

    // ==============================
    // 11. Mobile menu toggle
    // ==============================
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebarLeft = document.querySelector('.sidebar-left');
    
    if (mobileMenuToggle && sidebarLeft) {
        mobileMenuToggle.addEventListener('click', () => {
            const isVisible = sidebarLeft.style.display === 'flex';
            sidebarLeft.style.display = isVisible ? 'none' : 'flex';
            sidebarLeft.style.position = isVisible ? '' : 'fixed';
            sidebarLeft.style.top = isVisible ? '' : 'var(--navbar-height)';
            sidebarLeft.style.left = isVisible ? '' : '0';
            sidebarLeft.style.zIndex = isVisible ? '' : '999';
            sidebarLeft.style.background = isVisible ? '' : 'var(--bg)';
            sidebarLeft.style.width = isVisible ? '' : '260px';
            sidebarLeft.style.height = isVisible ? '' : 'calc(100vh - var(--navbar-height))';
            sidebarLeft.style.borderRight = isVisible ? '' : '1px solid var(--border)';
        });
    }

    // ==============================
    // 12. Perspective Debates: Opinion Poll Voting
    // ==============================
    const pollCard = document.getElementById('opinionPollCard');
    if (pollCard) {
        const debateId = pollCard.dataset.debateId;
        const pollVoteBtns = pollCard.querySelectorAll('.poll-vote-btn');

        pollVoteBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const vote = btn.dataset.vote;

                fetch('debate_vote_poll.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        debate_id: debateId,
                        vote: vote,
                        csrf_token: csrfToken
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        // Update buttons state
                        pollVoteBtns.forEach(b => b.classList.remove('voted'));
                        btn.classList.add('voted');

                        // Update gauge fills
                        const stats = data.stats;
                        const gaugeFor = document.getElementById('gaugeFor');
                        const gaugeUndecided = document.getElementById('gaugeUndecided');
                        const gaugeAgainst = document.getElementById('gaugeAgainst');

                        if (gaugeFor) {
                            gaugeFor.style.width = stats.pct_for + '%';
                            gaugeFor.textContent = stats.pct_for >= 10 ? stats.pct_for + '% FOR' : '';
                        }
                        if (gaugeUndecided) {
                            gaugeUndecided.style.width = stats.pct_undecided + '%';
                            gaugeUndecided.textContent = stats.pct_undecided >= 10 ? stats.pct_undecided + '%' : '';
                        }
                        if (gaugeAgainst) {
                            gaugeAgainst.style.width = stats.pct_against + '%';
                            gaugeAgainst.textContent = stats.pct_against >= 10 ? stats.pct_against + '% AGAINST' : '';
                        }

                        // Update breakdown numbers
                        const pctFor = document.getElementById('pctFor');
                        const countFor = document.getElementById('countFor');
                        const pctUndecided = document.getElementById('pctUndecided');
                        const countUndecided = document.getElementById('countUndecided');
                        const pctAgainst = document.getElementById('pctAgainst');
                        const countAgainst = document.getElementById('countAgainst');
                        const totalVotes = document.getElementById('pollTotalVotes');

                        if (pctFor) pctFor.textContent = stats.pct_for + '%';
                        if (countFor) countFor.textContent = stats.for;
                        if (pctUndecided) pctUndecided.textContent = stats.pct_undecided + '%';
                        if (countUndecided) countUndecided.textContent = stats.undecided;
                        if (pctAgainst) pctAgainst.textContent = stats.pct_against + '%';
                        if (countAgainst) countAgainst.textContent = stats.against;
                        if (totalVotes) totalVotes.innerHTML = `<span>${stats.total}</span> total votes`;
                    } else if (data.error) {
                        alert(data.error);
                    }
                })
                .catch(err => console.error('Poll vote error:', err));
            });
        });
    }

    // ==============================
    // 13. Perspective Debates: Argument Helpfulness Voting
    // ==============================
    const helpfulBtns = document.querySelectorAll('.helpful-vote-btn');
    helpfulBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const argId = btn.dataset.argumentId;

            fetch('vote_argument_helpful.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    argument_id: argId,
                    csrf_token: csrfToken
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const badge = btn.querySelector('.helpful-count-badge');
                    if (badge) badge.textContent = data.count;

                    if (data.voted) {
                        btn.classList.add('voted');
                    } else {
                        btn.classList.remove('voted');
                    }
                } else if (data.error) {
                    alert(data.error);
                }
            })
            .catch(err => console.error('Helpful vote error:', err));
        });
    });

    // ==============================
    // 14. Perspective Debates: Reply Toggle & Cancel
    // ==============================
    const replyToggles = document.querySelectorAll('.reply-toggle-btn');
    replyToggles.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.dataset.target;
            const target = document.getElementById(targetId);
            if (target) {
                target.style.display = (target.style.display === 'none' || target.style.display === '') ? 'block' : 'none';
                if (target.style.display === 'block') {
                    const ta = target.querySelector('textarea');
                    if (ta) ta.focus();
                }
            }
        });
    });

    const cancelReplyBtns = document.querySelectorAll('.cancel-reply-btn');
    cancelReplyBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.dataset.target;
            const target = document.getElementById(targetId);
            if (target) target.style.display = 'none';
        });
    });

    // ==============================
    // 15. Perspective Debates: Mobile Arena Tabs Switcher
    // ==============================
    const arenaMobileTabs = document.querySelectorAll('.arena-mobile-tabs .arena-tab');
    const colFor = document.getElementById('columnFor');
    const colAgainst = document.getElementById('columnAgainst');

    if (arenaMobileTabs.length > 0 && colFor && colAgainst) {
        colFor.classList.add('active-column');

        arenaMobileTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                arenaMobileTabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');

                const side = tab.dataset.side;
                if (side === 'for') {
                    colFor.classList.add('active-column');
                    colAgainst.classList.remove('active-column');
                } else {
                    colAgainst.classList.add('active-column');
                    colFor.classList.remove('active-column');
                }
            });
        });
    }

    // ==============================
    // 16. AI Conversation Insights
    // ==============================
    const btnAnalyze = document.getElementById('btnAnalyzeDiscussion');
    const btnReanalyze = document.getElementById('btnReanalyzeDiscussion');
    const btnToggleCollapse = document.getElementById('btnToggleInsightsCollapse');
    const insightsPanel = document.getElementById('aiInsightsPanel');
    const triggerBanner = document.getElementById('aiTriggerBanner');
    const loadingState = document.getElementById('aiLoadingState');
    const panelBody = document.getElementById('aiPanelBody');
    const panelNotice = document.getElementById('aiPanelNotice');

    function renderInsightsData(data) {
        if (!data) return;

        // Model & Status
        const modelName = document.getElementById('aiModelName');
        const statusText = document.getElementById('aiStatusText');
        const countBadge = document.getElementById('aiCountBadge');

        if (modelName) modelName.textContent = data.model_used || 'Gemini 1.5 Flash';
        if (statusText) statusText.textContent = data.cached ? 'Stored in DB • Cached' : 'Freshly Synthesized';
        if (countBadge && data.post_count_analyzed) {
            countBadge.innerHTML = `<i class="fas fa-comments"></i> ${data.post_count_analyzed} contributions`;
            countBadge.style.display = 'inline-flex';
        }

        // Summary
        const summaryEl = document.getElementById('aiSummaryText');
        if (summaryEl) {
            summaryEl.innerHTML = (data.summary || '').replace(/\n/g, '<br>');
        }

        // Arguments For
        const forList = document.getElementById('aiArgsForList');
        if (forList) {
            forList.innerHTML = (data.arguments_for || []).map(item => `
                <li>
                    <i class="fas fa-check"></i>
                    <span>${escapeHtml(item)}</span>
                </li>
            `).join('');
        }

        // Arguments Against
        const againstList = document.getElementById('aiArgsAgainstList');
        if (againstList) {
            againstList.innerHTML = (data.arguments_against || []).map(item => `
                <li>
                    <i class="fas fa-xmark"></i>
                    <span>${escapeHtml(item)}</span>
                </li>
            `).join('');
        }

        // Common Ground
        const commonList = document.getElementById('aiCommonGroundList');
        if (commonList) {
            commonList.innerHTML = (data.common_ground || []).map(item => `
                <li>
                    <i class="fas fa-circle-dot"></i>
                    <span>${escapeHtml(item)}</span>
                </li>
            `).join('');
        }

        // Unresolved Questions
        const unresolvedList = document.getElementById('aiUnresolvedList');
        if (unresolvedList) {
            unresolvedList.innerHTML = (data.unresolved_questions || []).map(item => `
                <li>
                    <i class="fas fa-arrow-right"></i>
                    <span>${escapeHtml(item)}</span>
                </li>
            `).join('');
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async function triggerAnalysis(type, id, force = false, triggerBtn = null) {
        if (!type || !id) return;

        // UI Loading Transition
        if (triggerBanner) triggerBanner.classList.add('has-insights-hidden');
        if (insightsPanel) {
            insightsPanel.style.display = 'block';
            insightsPanel.classList.remove('collapsed');
        }
        if (loadingState) loadingState.style.display = 'block';
        if (panelBody) panelBody.style.display = 'none';
        if (panelNotice) panelNotice.style.display = 'none';

        const originalBtnHtml = triggerBtn ? triggerBtn.innerHTML : null;
        if (triggerBtn) {
            triggerBtn.disabled = true;
            triggerBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> <span>Analyzing...</span>';
        }

        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const formData = new FormData();
            formData.append('action', 'analyze');
            formData.append('type', type);
            formData.append('id', id);
            if (force) formData.append('force', '1');
            formData.append('csrf_token', token);

            const res = await fetch('ai_insights_ajax.php', {
                method: 'POST',
                body: formData
            });

            const json = await res.json();

            if (!json.success) {
                throw new Error(json.error || 'Failed to analyze discussion.');
            }

            renderInsightsData(json.insights);

            if (panelBody) panelBody.style.display = 'flex';
            if (loadingState) loadingState.style.display = 'none';

            // Smooth scroll into view
            if (insightsPanel) {
                insightsPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

        } catch (err) {
            console.error('AI Insights Error:', err);
            if (loadingState) loadingState.style.display = 'none';
            if (panelNotice) {
                panelNotice.style.display = 'block';
                panelNotice.innerHTML = `<i class="fas fa-triangle-exclamation"></i> <strong>Analysis notice:</strong> ${escapeHtml(err.message)}`;
            }
            if (panelBody) panelBody.style.display = 'flex';
        } finally {
            if (triggerBtn) {
                triggerBtn.disabled = false;
                triggerBtn.innerHTML = originalBtnHtml;
            }
        }
    }

    if (btnAnalyze) {
        btnAnalyze.addEventListener('click', (e) => {
            e.preventDefault();
            const type = btnAnalyze.dataset.type;
            const id = btnAnalyze.dataset.id;
            triggerAnalysis(type, id, false, btnAnalyze);
        });
    }

    if (btnReanalyze) {
        btnReanalyze.addEventListener('click', (e) => {
            e.preventDefault();
            const type = btnReanalyze.dataset.type;
            const id = btnReanalyze.dataset.id;
            triggerAnalysis(type, id, true, btnReanalyze);
        });
    }

    if (btnToggleCollapse && insightsPanel) {
        btnToggleCollapse.addEventListener('click', (e) => {
            e.preventDefault();
            const isCollapsed = insightsPanel.classList.toggle('collapsed');
            btnToggleCollapse.innerHTML = isCollapsed 
                ? '<i class="fas fa-chevron-down"></i>' 
                : '<i class="fas fa-chevron-up"></i>';
            btnToggleCollapse.title = isCollapsed ? 'Expand AI Insights' : 'Collapse AI Insights';
        });
    }

    // ==============================
    // 17. Proposal Community Voting
    // ==============================
    const voteWidget = document.getElementById('proposalVoteWidget');
    if (voteWidget) {
        const proposalId = voteWidget.dataset.id;
        const btnUp = document.getElementById('btnVoteUp');
        const btnDown = document.getElementById('btnVoteDown');
        const tallyNumber = document.querySelector('#proposalVoteTally .tally-number');

        async function castProposalVote(voteType) {
            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const formData = new FormData();
            formData.append('proposal_id', proposalId);
            formData.append('vote_type', voteType);
            formData.append('csrf_token', token);

            try {
                const res = await fetch('vote_proposal.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    if (tallyNumber) {
                        tallyNumber.textContent = data.votes_count;
                    }
                    if (btnUp && btnDown) {
                        btnUp.classList.remove('active');
                        btnDown.classList.remove('active');
                        if (data.user_vote === 'up') btnUp.classList.add('active');
                        if (data.user_vote === 'down') btnDown.classList.add('active');
                    }
                } else {
                    alert(data.error || 'Failed to register vote.');
                }
            } catch (err) {
                console.error('Proposal voting error:', err);
            }
        }

        if (btnUp) {
            btnUp.addEventListener('click', (e) => {
                e.preventDefault();
                castProposalVote('up');
            });
        }

        if (btnDown) {
            btnDown.addEventListener('click', (e) => {
                e.preventDefault();
                castProposalVote('down');
            });
        }
    }

    // ==============================
    // 16. Save / Bookmark Discussion Handler
    // ==============================
    const saveBtns = document.querySelectorAll('.save-discussion-btn');
    saveBtns.forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const itemType = btn.dataset.type || 'topic';
            const itemId = btn.dataset.id;
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

            if (!itemId) return;

            try {
                const res = await fetch('toggle_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ item_type: itemType, item_id: itemId, csrf_token: csrf })
                });
                const data = await res.json();
                if (data.success) {
                    btn.dataset.saved = data.saved ? 'true' : 'false';
                    const icon = btn.querySelector('i');
                    const text = btn.querySelector('.save-btn-text');

                    if (icon) {
                        if (data.saved) {
                            icon.className = 'fas fa-bookmark';
                            icon.style.color = 'var(--accent-rose)';
                        } else {
                            icon.className = 'far fa-bookmark';
                            icon.style.color = 'inherit';
                        }
                    }
                    if (text) text.textContent = data.saved ? 'Saved' : 'Save';

                    // If on profile saved tab and item removed, remove item card
                    if (!data.saved && btn.closest('.activity-history-item')) {
                        const itemCard = btn.closest('.activity-history-item');
                        itemCard.style.transition = 'opacity 0.3s, transform 0.3s';
                        itemCard.style.opacity = '0';
                        itemCard.style.transform = 'translateX(20px)';
                        setTimeout(() => itemCard.remove(), 300);
                    }
                } else {
                    alert(data.error || 'Failed to update bookmark.');
                }
            } catch (err) {
                console.error('Save error:', err);
            }
        });
    });

    // ==============================
    // 17. Follow Discussion Handler
    // ==============================
    const followBtns = document.querySelectorAll('.follow-discussion-btn');
    followBtns.forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const itemType = btn.dataset.type || 'topic';
            const itemId = btn.dataset.id;
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

            if (!itemId) return;

            try {
                const res = await fetch('toggle_follow.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ item_type: itemType, item_id: itemId, csrf_token: csrf })
                });
                const data = await res.json();
                if (data.success) {
                    btn.dataset.followed = data.followed ? 'true' : 'false';
                    const icon = btn.querySelector('i');
                    const text = btn.querySelector('.follow-btn-text');

                    if (icon) {
                        if (data.followed) {
                            icon.className = 'fas fa-bell-slash';
                            icon.style.color = 'var(--accent-sky)';
                        } else {
                            icon.className = 'far fa-bell';
                            icon.style.color = 'inherit';
                        }
                    }
                    if (text) text.textContent = data.followed ? 'Following' : 'Follow';

                    if (!data.followed && btn.closest('.activity-history-item')) {
                        const itemCard = btn.closest('.activity-history-item');
                        itemCard.style.transition = 'opacity 0.3s, transform 0.3s';
                        itemCard.style.opacity = '0';
                        itemCard.style.transform = 'translateX(20px)';
                        setTimeout(() => itemCard.remove(), 300);
                    }
                } else {
                    alert(data.error || 'Failed to update follow preference.');
                }
            } catch (err) {
                console.error('Follow error:', err);
            }
        });
    });

    // ==============================
    // 18. Block / Unblock User Handler
    // ==============================
    const blockBtns = document.querySelectorAll('.block-user-btn');
    blockBtns.forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const targetUserId = btn.dataset.userId;
            const isCurrentlyBlocked = (btn.dataset.blocked === 'true');
            const promptMsg = isCurrentlyBlocked 
                ? 'Are you sure you want to unblock this user?' 
                : 'Are you sure you want to block this user? Their discussions and replies will be hidden from your feed.';

            if (!confirm(promptMsg)) return;

            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

            try {
                const res = await fetch('block_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: targetUserId, csrf_token: csrf })
                });
                const data = await res.json();
                if (data.success) {
                    btn.dataset.blocked = data.is_blocked ? 'true' : 'false';
                    const textSpan = btn.querySelector('.block-btn-text');
                    const icon = btn.querySelector('i');

                    if (data.is_blocked) {
                        if (textSpan) textSpan.textContent = 'Unblock';
                        if (icon) icon.className = 'fas fa-user-plus';
                        alert(data.message || 'User blocked.');
                        location.reload();
                    } else {
                        if (textSpan) textSpan.textContent = 'Block';
                        if (icon) icon.className = 'fas fa-user-slash';
                        alert(data.message || 'User unblocked.');
                        location.reload();
                    }
                } else {
                    alert(data.error || 'Failed to change block status.');
                }
            } catch (err) {
                console.error('Block error:', err);
            }
        });
    });

    // ==============================
    // 19. Navbar Notifications Dropdown & Realtime Poller
    // ==============================
    const navNotifWrap = document.getElementById('navNotifWrap');
    const navNotifBtn = document.getElementById('navNotifBtn');
    const navNotifDropdown = document.getElementById('navNotifDropdown');
    const navNotifBadge = document.getElementById('navNotifBadge');
    const navNotifList = document.getElementById('navNotifList');
    const markAllReadBtn = document.getElementById('markAllReadBtn');

    if (navNotifBtn && navNotifDropdown) {
        let notifsLoaded = false;

        async function loadNotifications() {
            try {
                const res = await fetch('notifications_ajax.php?action=get_recent');
                const data = await res.json();
                if (data.success) {
                    if (navNotifList) navNotifList.innerHTML = data.html;
                    updateBadge(data.count);
                    notifsLoaded = true;
                }
            } catch (err) {
                if (navNotifList) {
                    navNotifList.innerHTML = '<div style="padding:1rem; text-align:center; color:var(--text-muted); font-size:0.85rem;">Failed to load notifications</div>';
                }
            }
        }

        function updateBadge(count) {
            if (navNotifBadge) {
                if (count > 0) {
                    navNotifBadge.textContent = count > 99 ? '99+' : count;
                    navNotifBadge.classList.add('visible');
                } else {
                    navNotifBadge.textContent = '0';
                    navNotifBadge.classList.remove('visible');
                }
            }
        }

        navNotifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = navNotifDropdown.classList.contains('active');
            if (!isOpen) {
                navNotifDropdown.classList.add('active');
                loadNotifications();
            } else {
                navNotifDropdown.classList.remove('active');
            }
        });

        // Close on click outside
        document.addEventListener('click', (e) => {
            if (navNotifWrap && !navNotifWrap.contains(e.target)) {
                navNotifDropdown.classList.remove('active');
            }
        });

        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                try {
                    const res = await fetch('notifications_ajax.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'mark_all_read', csrf_token: csrf })
                    });
                    const data = await res.json();
                    if (data.success) {
                        updateBadge(0);
                        const unreadItems = navNotifDropdown.querySelectorAll('.notif-dropdown-item.unread');
                        unreadItems.forEach(el => el.classList.remove('unread'));
                    }
                } catch (err) {
                    console.error('Mark read error:', err);
                }
            });
        }
    }
});




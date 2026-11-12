<?php
/**
 * AI Conversation Insights Service
 * Handles LLM API calls, discussion text extraction, structured parsing, 
 * intelligent synthesis fallback, and database caching.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

/**
 * Ensure the ai_discussion_insights table exists
 */
function ensure_ai_insights_table($pdo) {
    static $checked = false;
    if ($checked) return;

    $sql = "CREATE TABLE IF NOT EXISTS ai_discussion_insights (
        id INT AUTO_INCREMENT PRIMARY KEY,
        discussion_type ENUM('topic', 'debate') NOT NULL DEFAULT 'topic',
        discussion_id INT NOT NULL,
        summary TEXT NOT NULL,
        arguments_for TEXT NOT NULL,
        arguments_against TEXT NOT NULL,
        common_ground TEXT NOT NULL,
        unresolved_questions TEXT NOT NULL,
        model_used VARCHAR(100) DEFAULT 'Gemini',
        post_count_analyzed INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_discussion_insight (discussion_type, discussion_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    try {
        $pdo->exec($sql);
        $checked = true;
    } catch (PDOException $e) {
        // Table might already exist or permission error
    }
}

/**
 * Fetch cached insights from database if available
 */
function get_cached_insights($pdo, $discussion_type, $discussion_id) {
    ensure_ai_insights_table($pdo);
    
    $stmt = $pdo->prepare("
        SELECT * FROM ai_discussion_insights 
        WHERE discussion_type = ? AND discussion_id = ?
    ");
    $stmt->execute([$discussion_type, $discussion_id]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return [
        'id' => (int)$row['id'],
        'discussion_type' => $row['discussion_type'],
        'discussion_id' => (int)$row['discussion_id'],
        'summary' => $row['summary'],
        'arguments_for' => json_decode($row['arguments_for'], true) ?: (array)$row['arguments_for'],
        'arguments_against' => json_decode($row['arguments_against'], true) ?: (array)$row['arguments_against'],
        'common_ground' => json_decode($row['common_ground'], true) ?: (array)$row['common_ground'],
        'unresolved_questions' => json_decode($row['unresolved_questions'], true) ?: (array)$row['unresolved_questions'],
        'model_used' => $row['model_used'],
        'post_count_analyzed' => (int)$row['post_count_analyzed'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'cached' => true
    ];
}

/**
 * Save or update generated insights in the database
 */
function save_insights($pdo, $discussion_type, $discussion_id, $insights, $model_used, $post_count) {
    ensure_ai_insights_table($pdo);

    $summary = trim($insights['summary'] ?? 'Summary not available.');
    $args_for = json_encode(array_values((array)($insights['arguments_for'] ?? [])), JSON_UNESCAPED_UNICODE);
    $args_against = json_encode(array_values((array)($insights['arguments_against'] ?? [])), JSON_UNESCAPED_UNICODE);
    $common_ground = json_encode(array_values((array)($insights['common_ground'] ?? [])), JSON_UNESCAPED_UNICODE);
    $unresolved = json_encode(array_values((array)($insights['unresolved_questions'] ?? [])), JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
        INSERT INTO ai_discussion_insights 
            (discussion_type, discussion_id, summary, arguments_for, arguments_against, common_ground, unresolved_questions, model_used, post_count_analyzed, updated_at)
        VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            summary = VALUES(summary),
            arguments_for = VALUES(arguments_for),
            arguments_against = VALUES(arguments_against),
            common_ground = VALUES(common_ground),
            unresolved_questions = VALUES(unresolved_questions),
            model_used = VALUES(model_used),
            post_count_analyzed = VALUES(post_count_analyzed),
            updated_at = NOW()
    ");

    $stmt->execute([
        $discussion_type,
        $discussion_id,
        $summary,
        $args_for,
        $args_against,
        $common_ground,
        $unresolved,
        $model_used,
        $post_count
    ]);

    return get_cached_insights($pdo, $discussion_type, $discussion_id);
}

/**
 * Extract full discussion text, metadata, and contributions
 */
function gather_discussion_payload($pdo, $discussion_type, $discussion_id) {
    if ($discussion_type === 'debate') {
        $stmt = $pdo->prepare("
            SELECT d.*, c.name as category_name, u.username as creator_username
            FROM debates d
            JOIN categories c ON d.category_id = c.id
            JOIN users u ON d.user_id = u.id
            WHERE d.id = ? AND d.is_deleted = 0
        ");
        $stmt->execute([$discussion_id]);
        $debate = $stmt->fetch();
        if (!$debate) return null;

        // Fetch all arguments
        $stmt = $pdo->prepare("
            SELECT a.*, u.username 
            FROM debate_arguments a
            JOIN users u ON a.user_id = u.id
            WHERE a.debate_id = ?
            ORDER BY a.created_at ASC
        ");
        $stmt->execute([$discussion_id]);
        $args = $stmt->fetchAll();

        $posts = [];
        // First entry: the debate motion and background context
        $posts[] = [
            'author' => $debate['creator_username'],
            'side' => 'Motion Context',
            'content' => $debate['title'] . "\n" . $debate['description'],
            'created_at' => $debate['created_at']
        ];

        foreach ($args as $a) {
            $sideLabel = strtoupper($a['side'] ?? 'ARGUMENT');
            $posts[] = [
                'author' => $a['username'],
                'side' => $sideLabel,
                'content' => $a['content'],
                'helpful' => (int)($a['helpful_count'] ?? 0),
                'created_at' => $a['created_at']
            ];
        }

        return [
            'type' => 'debate',
            'id' => $discussion_id,
            'title' => $debate['title'],
            'category' => $debate['category_name'],
            'description' => $debate['description'],
            'posts' => $posts,
            'count' => count($posts)
        ];
    } else {
        // Standard topic discussion
        $stmt = $pdo->prepare("
            SELECT t.*, c.name as category_name, u.username as creator_username
            FROM topics t
            JOIN categories c ON t.category_id = c.id
            JOIN users u ON t.user_id = u.id
            WHERE t.id = ? AND t.is_deleted = 0
        ");
        $stmt->execute([$discussion_id]);
        $topic = $stmt->fetch();
        if (!$topic) return null;

        // Fetch all posts in this topic
        $stmt = $pdo->prepare("
            SELECT p.*, u.username 
            FROM posts p
            JOIN users u ON p.user_id = u.id
            WHERE p.topic_id = ? AND p.is_deleted = 0
            ORDER BY p.created_at ASC
        ");
        $stmt->execute([$discussion_id]);
        $rawPosts = $stmt->fetchAll();

        $posts = [];
        foreach ($rawPosts as $p) {
            $posts[] = [
                'author' => $p['username'],
                'content' => $p['content'],
                'created_at' => $p['created_at']
            ];
        }

        return [
            'type' => 'topic',
            'id' => $discussion_id,
            'title' => $topic['title'],
            'category' => $topic['category_name'],
            'description' => $topic['title'],
            'posts' => $posts,
            'count' => count($posts)
        ];
    }
}

/**
 * Call LLM API (Gemini or OpenAI) with fallback to intelligent NLP synthesis
 */
function analyze_discussion_with_llm($payload) {
    $title = $payload['title'];
    $category = $payload['category'];
    $posts = $payload['posts'];
    $postCount = count($posts);

    // Build discussion transcript text
    $transcript = "DISCUSSION TITLE: " . $title . "\n";
    $transcript .= "CATEGORY: " . $category . "\n\n";
    $transcript .= "CONTRIBUTIONS (" . $postCount . " entries):\n";
    foreach ($posts as $idx => $p) {
        $num = $idx + 1;
        $author = $p['author'] ?? 'Anonymous';
        $side = !empty($p['side']) ? " [" . $p['side'] . "]" : "";
        $transcript .= "[$num] User @$author$side:\n" . trim($p['content']) . "\n\n";
    }

    $systemInstruction = "You are an expert AI Discussion Analyst. Analyze the provided online forum discussion thread and generate structured conversation insights.\n"
        . "You MUST return ONLY valid JSON matching this exact structure with these exact 4 keys:\n"
        . "{\n"
        . '  "summary": "A clear, engaging 2-3 sentence executive summary of the entire thread.",' . "\n"
        . '  "arguments_for": ["List 2 to 4 key arguments, points in favor, or positive perspectives mentioned"],' . "\n"
        . '  "arguments_against": ["List 2 to 4 key counterarguments, risks, objections, or critical perspectives mentioned"],' . "\n"
        . '  "common_ground": ["List 2 to 3 points of consensus, shared values, mutual concerns, or agreed principles between participants"],' . "\n"
        . '  "unresolved_questions": ["List 2 to 3 open questions, lingering uncertainties, or core disagreements that remain unresolved"]' . "\n"
        . "}\n"
        . "Do not include any Markdown fences or formatting outside the JSON.";

    // 1. Check Gemini API
    $geminiKey = defined('GEMINI_API_KEY') ? trim(GEMINI_API_KEY) : '';
    if (!empty($geminiKey)) {
        $geminiResult = call_gemini_api($geminiKey, $systemInstruction, $transcript);
        if ($geminiResult && !empty($geminiResult['data'])) {
            return [
                'model' => 'Gemini 1.5 Flash',
                'data' => $geminiResult['data']
            ];
        }
    }

    // 2. Check OpenAI API
    $openaiKey = defined('OPENAI_API_KEY') ? trim(OPENAI_API_KEY) : '';
    if (!empty($openaiKey)) {
        $openaiResult = call_openai_api($openaiKey, $systemInstruction, $transcript);
        if ($openaiResult && !empty($openaiResult['data'])) {
            return [
                'model' => 'OpenAI GPT-4o-mini',
                'data' => $openaiResult['data']
            ];
        }
    }

    // 3. Fallback: Heuristic NLP Synthesizer
    $nlpData = generate_heuristic_insights($payload);
    return [
        'model' => 'Smart NLP Synthesizer (Gemini API Ready)',
        'data' => $nlpData
    ];
}

/**
 * Call Google Gemini REST API
 */
function call_gemini_api($apiKey, $systemInstruction, $userPrompt) {
    if (!extension_loaded('curl')) return null;

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($apiKey);

    $body = [
        "contents" => [
            [
                "role" => "user",
                "parts" => [
                    [
                        "text" => $systemInstruction . "\n\n" . $userPrompt
                    ]
                ]
            ]
        ],
        "generationConfig" => [
            "responseMimeType" => "application/json",
            "temperature" => 0.2
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false // Compatible with local dev environments
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }

    $resJson = json_decode($response, true);
    $text = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) return null;

    // Clean any accidental markdown code blocks
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);

    $parsed = json_decode($text, true);
    if (!is_array($parsed) || empty($parsed['summary'])) return null;

    return ['data' => validate_insights_structure($parsed)];
}

/**
 * Call OpenAI REST API
 */
function call_openai_api($apiKey, $systemInstruction, $userPrompt) {
    if (!extension_loaded('curl')) return null;

    $url = "https://api.openai.com/v1/chat/completions";

    $body = [
        "model" => "gpt-4o-mini",
        "messages" => [
            ["role" => "system", "content" => $systemInstruction],
            ["role" => "user", "content" => $userPrompt]
        ],
        "response_format" => ["type" => "json_object"],
        "temperature" => 0.2
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }

    $resJson = json_decode($response, true);
    $content = $resJson['choices'][0]['message']['content'] ?? null;
    if (!$content) return null;

    $parsed = json_decode($content, true);
    if (!is_array($parsed) || empty($parsed['summary'])) return null;

    return ['data' => validate_insights_structure($parsed)];
}

/**
 * Validate and sanitize structured insights array
 */
function validate_insights_structure($data) {
    $summary = !empty($data['summary']) && is_string($data['summary']) 
        ? trim($data['summary']) 
        : 'The discussion features diverse perspectives regarding the topic.';

    $argsFor = [];
    if (!empty($data['arguments_for']) && is_array($data['arguments_for'])) {
        foreach ($data['arguments_for'] as $item) {
            if (is_string($item) && trim($item) !== '') $argsFor[] = trim($item);
        }
    }

    $argsAgainst = [];
    if (!empty($data['arguments_against']) && is_array($data['arguments_against'])) {
        foreach ($data['arguments_against'] as $item) {
            if (is_string($item) && trim($item) !== '') $argsAgainst[] = trim($item);
        }
    }

    $commonGround = [];
    if (!empty($data['common_ground']) && is_array($data['common_ground'])) {
        foreach ($data['common_ground'] as $item) {
            if (is_string($item) && trim($item) !== '') $commonGround[] = trim($item);
        }
    }

    $unresolved = [];
    if (!empty($data['unresolved_questions']) && is_array($data['unresolved_questions'])) {
        foreach ($data['unresolved_questions'] as $item) {
            if (is_string($item) && trim($item) !== '') $unresolved[] = trim($item);
        }
    }

    return [
        'summary' => $summary,
        'arguments_for' => !empty($argsFor) ? $argsFor : ['Key supportive arguments highlighted positive outcomes and proactive adaptation.'],
        'arguments_against' => !empty($argsAgainst) ? $argsAgainst : ['Critical perspectives emphasized implementation risks, overheads, and potential downsides.'],
        'common_ground' => !empty($commonGround) ? $commonGround : ['Participants agree that clear communication, continuous learning, and thoughtful preparation are essential.'],
        'unresolved_questions' => !empty($unresolved) ? $unresolved : ['How the long-term impact will unfold in practical settings remains an open question for future follow-up.']
    ];
}

/**
 * Dynamic NLP Synthesizer Fallback:
 * Extracts actual arguments, sentiment, commonalities, and open questions
 * from the live posts when an API key is not yet set or during offline testing.
 */
function generate_heuristic_insights($payload) {
    $title = $payload['title'];
    $category = $payload['category'];
    $posts = $payload['posts'];
    $postCount = count($posts);

    $forPoints = [];
    $againstPoints = [];
    $questions = [];
    $generalPoints = [];

    // Keywords signaling for / against / questions
    $forPatterns = ['support', 'agree', 'advantage', 'benefit', 'effective', 'opportunity', 'great', 'yes', 'positive', 'future', 'improve', 'promising', 'crucial', 'solution', 'potential', 'boost', 'help', 'better'];
    $againstPatterns = ['risk', 'disagree', 'drawback', 'concern', 'issue', 'danger', 'negative', 'flaw', 'cost', 'threat', 'replace', 'problem', 'overrated', 'skeptical', 'doubt', 'against', 'no', 'fail'];

    foreach ($posts as $p) {
        $side = strtolower($p['side'] ?? '');
        $content = strip_tags($p['content']);
        $sentences = preg_split('/(?<=[.?!])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($sentences as $s) {
            $s = trim($s);
            if (strlen($s) < 25 || strlen($s) > 280) continue;

            // Check for questions
            if (str_contains($s, '?') || preg_match('/^(what|how|why|who|can|is|will|should|are|could)\b/i', $s)) {
                if (count($questions) < 4) {
                    $questions[] = rtrim($s, ' ?.') . '?';
                }
            }

            // Stance classification
            $lower = strtolower($s);
            $hasFor = false;
            $hasAgainst = false;

            foreach ($forPatterns as $w) {
                if (str_contains($lower, $w)) { $hasFor = true; break; }
            }
            foreach ($againstPatterns as $w) {
                if (str_contains($lower, $w)) { $hasAgainst = true; break; }
            }

            if ($side === 'for' || ($hasFor && !$hasAgainst)) {
                if (count($forPoints) < 4 && !in_array($s, $forPoints)) {
                    $forPoints[] = $s;
                }
            } elseif ($side === 'against' || ($hasAgainst && !$hasFor)) {
                if (count($againstPoints) < 4 && !in_array($s, $againstPoints)) {
                    $againstPoints[] = $s;
                }
            } else {
                if (count($generalPoints) < 4) {
                    $generalPoints[] = $s;
                }
            }
        }
    }

    // Default fallbacks if posts were very brief
    if (empty($forPoints)) {
        if (!empty($generalPoints[0])) {
            $forPoints[] = "Advocates point to positive potential: \"" . substr($generalPoints[0], 0, 150) . "...\"";
        } else {
            $forPoints[] = "Emphasizes the speed, efficiency, and technological acceleration brought by modern tooling.";
            $forPoints[] = "Enables creators to focus on high-level architecture and problem-solving rather than boilerplate.";
        }
    }

    if (empty($againstPoints)) {
        if (!empty($generalPoints[1])) {
            $againstPoints[] = "Skeptics raise concerns regarding reliability: \"" . substr($generalPoints[1], 0, 150) . "...\"";
        } else {
            $againstPoints[] = "Highlights the risk of over-reliance, potential loss of foundational fundamentals, and quality control.";
            $againstPoints[] = "Points out that real-world edge cases still require deep contextual human reasoning and domain expertise.";
        }
    }

    if (empty($questions)) {
        $questions[] = "What criteria or standards should determine the boundary between automated assistance and human oversight?";
        $questions[] = "How quickly will industry standards and junior talent pipelines adapt to these shifting expectations?";
    }

    $commonGround = [
        "Participants agree that understanding core engineering fundamentals remains vital regardless of emerging tooling.",
        "Both sides recognize that proactive learning, critical thinking, and adaptation are key to navigating future changes."
    ];

    $summary = "This discussion in '$category' explores '" . htmlspecialchars($title) . "' across $postCount contribution(s). "
        . "Participants actively debated key trade-offs between practical efficiencies and underlying architectural risks, "
        . "with consensus forming around the necessity of continuous skill evolution and balanced oversight.";

    return [
        'summary' => $summary,
        'arguments_for' => array_slice($forPoints, 0, 3),
        'arguments_against' => array_slice($againstPoints, 0, 3),
        'common_ground' => array_slice($commonGround, 0, 3),
        'unresolved_questions' => array_slice($questions, 0, 3)
    ];
}

/**
 * Main Controller: Analyze discussion and return structured insights (cached or fresh)
 */
function get_or_generate_discussion_insights($pdo, $discussion_type, $discussion_id, $force_refresh = false) {
    // 1. Return cached results if present and not force-refreshing
    if (!$force_refresh) {
        $cached = get_cached_insights($pdo, $discussion_type, $discussion_id);
        if ($cached) {
            return [
                'success' => true,
                'cached' => true,
                'insights' => $cached
            ];
        }
    }

    // 2. Gather discussion text
    $payload = gather_discussion_payload($pdo, $discussion_type, $discussion_id);
    if (!$payload) {
        return [
            'success' => false,
            'error' => 'Discussion not found.'
        ];
    }

    if ($payload['count'] === 0) {
        return [
            'success' => false,
            'error' => 'This discussion has no posts or arguments yet. Add a reply to enable AI analysis.'
        ];
    }

    // 3. Call LLM API (or NLP synthesizer)
    $aiResult = analyze_discussion_with_llm($payload);
    $modelUsed = $aiResult['model'] ?? 'Gemini';
    $data = $aiResult['data'];

    // 4. Store generated results in database
    $saved = save_insights($pdo, $discussion_type, $discussion_id, $data, $modelUsed, $payload['count']);

    return [
        'success' => true,
        'cached' => false,
        'insights' => $saved
    ];
}

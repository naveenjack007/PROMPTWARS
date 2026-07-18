<?php
// Local Sentiment Analysis Engine
// Uses a weighted lexicon tailored for addiction recovery contexts.

class SentimentAnalyzer {
    // Word list with weights (-5 to +5)
    private static $lexicon = [
        // Negative / Struggle indicators
        'struggle' => -2,
        'struggling' => -2,
        'addicted' => -3,
        'addiction' => -2,
        'anxious' => -2,
        'anxiety' => -2,
        'depressed' => -3,
        'depression' => -3,
        'crave' => -2,
        'craving' => -2,
        'cravings' => -2,
        'helpless' => -3,
        'hopeless' => -4,
        'guilt' => -2,
        'guilty' => -2,
        'shame' => -3,
        'ashamed' => -3,
        'sad' => -2,
        'sadness' => -2,
        'angry' => -2,
        'anger' => -2,
        'stress' => -2,
        'stressed' => -2,
        'tired' => -1,
        'fail' => -2,
        'failed' => -3,
        'failure' => -3,
        'relapse' => -3,
        'relapsed' => -3,
        'hard' => -1,
        'difficult' => -1,
        'pain' => -2,
        'painful' => -2,
        'lonely' => -2,
        'scared' => -2,
        'fear' => -2,
        'worst' => -3,
        'bad' => -1,
        'worry' => -2,
        'worried' => -2,
        'hate' => -2,
        'hating' => -2,
        'cannot' => -1,
        'cant' => -1,
        'unable' => -1,
        'lost' => -2,
        'empty' => -2,
        
        // Positive / Recovery indicators
        'hope' => 2,
        'hopeful' => 3,
        'hopefulness' => 3,
        'motivate' => 3,
        'motivated' => 3,
        'motivation' => 3,
        'clean' => 2,
        'healthy' => 2,
        'health' => 1,
        'happy' => 2,
        'happiness' => 2,
        'excited' => 3,
        'excitement' => 2,
        'ready' => 2,
        'commit' => 2,
        'committed' => 3,
        'commitment' => 2,
        'strong' => 3,
        'strength' => 3,
        'better' => 2,
        'improvement' => 2,
        'improve' => 2,
        'improving' => 2,
        'quit' => 2,
        'quitting' => 2,
        'stop' => 1,
        'stopped' => 1,
        'free' => 3,
        'freedom' => 3,
        'heal' => 3,
        'healing' => 3,
        'peace' => 2,
        'peaceful' => 2,
        'focus' => 2,
        'focused' => 2,
        'believe' => 2,
        'believing' => 2,
        'support' => 2,
        'supported' => 2,
        'love' => 2,
        'loving' => 2,
        'good' => 1,
        'great' => 2,
        'positive' => 2,
        'future' => 1,
        'change' => 1,
        'changing' => 1,
        'will' => 1,
        'can' => 1,
        'resolve' => 3,
        'recovery' => 3,
        'recover' => 2
    ];

    /**
     * Analyzes the sentiment of a text string
     * @param string $text The text to analyze
     * @return array Contains: score, label, and an empathetic analysis breakdown
     */
    public static function analyze($text) {
        if (empty(trim($text))) {
            return [
                'score' => 0,
                'label' => 'Neutral',
                'analysis' => 'No personal reflection was provided to analyze.'
            ];
        }

        // Clean and tokenize text
        $lowercase_text = strtolower($text);
        // Replace non-word characters with spaces
        $cleaned_text = preg_replace('/[^\w\s]/', ' ', $lowercase_text);
        // Split by whitespace
        $words = preg_split('/\s+/', $cleaned_text);
        
        $score = 0;
        $match_count = 0;
        $matched_words = [];

        foreach ($words as $word) {
            if (isset(self::$lexicon[$word])) {
                $weight = self::$lexicon[$word];
                $score += $weight;
                $match_count++;
                $matched_words[$word] = $weight;
            }
        }

        // Determine label
        if ($score > 1) {
            $label = 'Positive';
        } elseif ($score < -1) {
            $label = 'Negative';
        } else {
            $label = 'Neutral';
        }

        // Generate dynamic empathetic description based on score/context
        $analysis = self::generateEmpatheticFeedback($label, $score, $match_count, $matched_words);

        return [
            'score' => $score,
            'label' => $label,
            'analysis' => $analysis
        ];
    }

    /**
     * Generates a warm, supportive feedback summary based on the sentiment results
     */
    private static function generateEmpatheticFeedback($label, $score, $match_count, $matched_words) {
        if ($label === 'Positive') {
            return "Your bio-data shows high levels of motivation and optimism (Sentiment Score: +$score). " .
                   "Your words indicate that you feel empowered, ready for change, and focused on the benefits of recovery. " .
                   "This positive mindset is one of your greatest assets in overcoming this addiction!";
        } elseif ($label === 'Negative') {
            return "We detected underlying signs of stress, struggle, or vulnerability in your description (Sentiment Score: $score). " .
                   "It is completely normal and valid to feel overwhelmed, anxious, or self-critical at this stage. " .
                   "Admitting these feelings is a massive step of courage. Your coach will structure your plan to be highly supportive, " .
                   "focusing on gentle reductions, trigger management, and building self-compassion.";
        } else {
            return "Your response has a balanced, realistic, or neutral emotional tone (Sentiment Score: $score). " .
                   "You appear to be approaching this journey with a steady, objective mindset. " .
                   "This clear-headed perspective will help you follow your schedule systematically and analyze triggers without judgment.";
        }
    }
}
?>

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AIService
{
    /**
     * Generate a proposal from lead data and conversation notes.
     *
     * @throws \RuntimeException
     */
    public function generateProposal(array $leadData, string $notes, string $theme): array
    {
        // Read active config from shared DB
        $config = DB::table('ai_configs')->where('is_active', true)->first();

        if (! $config) {
            throw new \RuntimeException('No active AI config found. Please set up an AI configuration in the admin dashboard.');
        }

        $httpClient = new \GuzzleHttp\Client(['verify' => false]);

        $factory = \OpenAI::factory()
            ->withApiKey($config->api_key)
            ->withHttpClient($httpClient);

        // Route to provider's base URL
        $baseUris = [
            'groq' => 'https://api.groq.com/openai/v1',
            'xai'  => 'https://api.x.ai/v1',
        ];
        if (isset($baseUris[$config->provider])) {
            $factory = $factory->withBaseUri($baseUris[$config->provider]);
        }

        $client = $factory->make();

        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt   = $this->buildUserPrompt($leadData, $notes, $theme);

        try {
            $response = $client->chat()->create([
                'model'       => $config->model,
                'max_tokens'  => (int) $config->max_tokens,
                'temperature' => (float) $config->temperature,
                'messages'    => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
            ]);
        } catch (\OpenAI\Exceptions\RateLimitException $e) {
            throw new \RuntimeException('OpenAI rate limit exceeded. Please wait a moment and try again.');
        } catch (\OpenAI\Exceptions\AuthenticationException $e) {
            throw new \RuntimeException('Invalid OpenAI API key. Please update the AI config in the admin dashboard.');
        } catch (\OpenAI\Exceptions\ErrorException $e) {
            throw new \RuntimeException('OpenAI error: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not connect to OpenAI: ' . $e->getMessage());
        }

        $text = $response->choices[0]->message->content ?? '';

        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/```\s*$/m', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);

        if (! is_array($data)) {
            throw new \RuntimeException('AI returned an invalid response. Please try again.');
        }

        return $data;
    }

    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
You are an expert business proposal writer with 15+ years of experience closing high-value B2B deals.
Write compelling, persuasive proposals tailored to the client's industry and pain points.
Use confident, professional language that builds trust and demonstrates deep expertise.
Focus on measurable outcomes, ROI, and clear value delivery — not just features.
Make every section feel custom-written for this specific client, not generic.
CRITICAL: Respond with ONLY a valid JSON object. No markdown, no explanation, no code fences, no extra text — just the raw JSON.
PROMPT;
    }

    private function buildUserPrompt(array $lead, string $notes, string $theme): string
    {
        $value    = isset($lead['value']) ? '₹' . number_format((float) $lead['value'], 2) : 'Not specified';
        $fullName = $lead['full_name'] ?? 'Client';
        $company  = $lead['company']  ?? 'Client Company';
        $industry = $lead['industry'] ?? 'Not specified';
        $stage    = $lead['stage']['name'] ?? ($lead['stage_name'] ?? 'Unknown');

        return <<<PROMPT
Lead Information:
- Name: {$fullName}
- Company: {$company}
- Industry: {$industry}
- Current Stage: {$stage}
- Estimated Deal Value: {$value}

Sales Representative's Conversation Notes:
"{$notes}"

Proposal Theme: {$theme}

Generate a complete, detailed, professional business proposal as a valid JSON object with EXACTLY this structure:

{
  "title": "string — compelling proposal title mentioning the company name",

  "executive_summary": "string — EXACTLY 3 paragraphs: (1) acknowledge the client's challenge and business context, (2) describe the solution and our approach, (3) articulate the ROI and business impact they will achieve. Minimum 200 words.",

  "about_us": "string — 2-3 sentences: who we are, our expertise domain, years of experience, and the core value we deliver to clients like this one.",

  "why_choose_us": [
    "string — key differentiator 1 (e.g. 'Proven track record: 200+ successful projects delivered on time')",
    "string — key differentiator 2",
    "string — key differentiator 3",
    "string — key differentiator 4"
  ],

  "understanding": "string — detailed, empathetic understanding of the client's pain points, challenges, and strategic goals based on the conversation notes. Minimum 150 words. Reference specific details from the notes.",

  "client_pain_points": [
    { "title": "string — specific problem area (e.g. 'Outdated Visual Presentation', 'Weak Conversion Design')", "points": ["string — specific problem 1", "string — specific problem 2", "string — specific problem 3"] },
    { "title": "string", "points": ["string", "string", "string"] },
    { "title": "string", "points": ["string", "string"] },
    { "title": "string", "points": ["string", "string"] }
  ],

  "key_benefits": [
    { "title": "string — benefit headline", "description": "string — measurable outcome, e.g. '40% reduction in operational overhead within 3 months'" },
    { "title": "string", "description": "string" },
    { "title": "string", "description": "string" },
    { "title": "string", "description": "string" }
  ],

  "proposed_solution": {
    "overview": "string — 3-4 paragraph narrative of the full solution approach. Explain WHAT we will build/deliver and WHY it is the perfect fit for this client's specific situation. Minimum 200 words.",
    "approach": "string — our working methodology (e.g. Agile sprints, discovery workshops, iterative delivery). 2-3 sentences.",
    "technology_stack": ["Technology/tool 1", "Technology/tool 2", "Technology/tool 3"],
    "key_features": [
      { "feature": "string — feature or capability name", "benefit": "string — specific client benefit this delivers" },
      { "feature": "string", "benefit": "string" },
      { "feature": "string", "benefit": "string" },
      { "feature": "string", "benefit": "string" },
      { "feature": "string", "benefit": "string" }
    ],
    "differentiators": "string — what makes our proposed solution uniquely better than a generic or competitor approach. 2-3 sentences.",
    "success_metrics": [
      { "metric": "string — what we measure", "target": "string — e.g. '95% uptime SLA'" },
      { "metric": "string", "target": "string" },
      { "metric": "string", "target": "string" }
    ],
    "implementation_approach": "string — brief description of how we structure delivery: phased rollout, agile sprints, milestone gates, etc. 2-3 sentences."
  },

  "scope_of_work": [
    {
      "item": "string — deliverable name",
      "description": "string — detailed description of this deliverable",
      "key_deliverables": ["string — specific output 1", "string — specific output 2", "string — specific output 3"]
    }
  ],

  "timeline": [
    { "phase": "string — phase name", "duration": "string — e.g. '2 weeks'", "deliverables": "string — what is delivered in this phase", "start_week": number, "end_week": number }
  ],

  "platform_tech": [
    { "label": "Platform", "value": "string — primary platform or framework (e.g. 'React + Node.js', 'WordPress', 'Laravel')" },
    { "label": "Version", "value": "string — e.g. 'Web + Mobile Responsive'" },
    { "label": "Deployment", "value": "string — hosting/deployment (e.g. 'AWS', 'Vercel', 'On-Premise Server')" },
    { "label": "Database", "value": "string — e.g. 'PostgreSQL', 'MySQL', 'MongoDB'" }
  ],

  "team": [
    { "role": "string — e.g. 'Project Manager'", "responsibility": "string — what this person does on the project" },
    { "role": "string", "responsibility": "string" },
    { "role": "string", "responsibility": "string" }
  ],

  "investment": {
    "resource_breakdown": [
      { "resource": "string — role/resource name", "rate": number, "hours": number, "amount": number }
    ],
    "breakdown": [
      { "item": "string — line item name", "amount": number }
    ],
    "total": number,
    "currency": "INR",
    "payment_terms": "string — e.g. '50% upfront, 25% at midpoint, 25% on completion'"
  },

  "risk_mitigation": [
    { "risk": "string — potential risk", "mitigation": "string — how we address it" },
    { "risk": "string", "mitigation": "string" },
    { "risk": "string", "mitigation": "string" }
  ],

  "assumptions": [
    "string — assumption this proposal is based on",
    "string",
    "string"
  ],

  "maintenance_support": {
    "period": "string — e.g. '3 months', '6 months'",
    "includes": [
      "string — support item 1 (e.g. 'Technical support and bug fixes')",
      "string — support item 2 (e.g. 'Minor updates and revisions')",
      "string — support item 3 (e.g. 'Assistance via Email / Call within 24 hours')"
    ]
  },

  "terms_and_conditions": "string — professional terms covering IP ownership, confidentiality, revision policy, warranty, and dispute resolution.",

  "next_steps": "string — numbered list of specific action items with clear owners (Client vs Our Team). Include a call-to-action to sign and a timeline to begin.",

  "closing_note": "string — 1-2 warm sentences thanking the client for the opportunity and expressing excitement about working together. Keep it genuine and professional.",

  "validity_days": number
}

Rules:
- Make everything specific to {$company} in the {$industry} industry
- Reference the conversation notes throughout — do not write a generic proposal
- Base investment amounts realistically on the deal value {$value}
- client_pain_points: generate 4-6 cards, each with 2-4 bullet points specific to this client's situation
- Include at least 5 scope of work items, each with 3 key_deliverables
- Include at least 4 timeline phases with start_week and end_week
- platform_tech: generate 4-5 key-value rows specific to the technology needed for this project
- Include at least 4 team members
- Include at least 3 risk_mitigation entries
- Include at least 3 assumptions
- maintenance_support.includes: 3-4 clear support items
- Keep validity_days between 15 and 30
- Write in professional, persuasive business English
- Return ONLY the JSON object, nothing else
PROMPT;
    }
}

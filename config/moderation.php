<?php

return [
    'blocked_tags' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MODERATION_BLOCKED_TAGS', 'nudity,nude,explicitNudity,topless,femaleBreast,selfHarm'))
    ))),
    'soft_blocked_tags' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MODERATION_SOFT_BLOCKED_TAGS', ''))
    ))),
    'soft_block_hits_required' => max(1, (int) env('MODERATION_SOFT_BLOCK_HITS_REQUIRED', 2)),
    'soft_block_distinct_tags_required' => max(1, (int) env('MODERATION_SOFT_BLOCK_DISTINCT_TAGS_REQUIRED', 2)),
    'soft_block_distinct_photos_required' => max(1, (int) env('MODERATION_SOFT_BLOCK_DISTINCT_PHOTOS_REQUIRED', 2)),
    'context_sensitive_blocked_tags' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MODERATION_CONTEXT_SENSITIVE_BLOCKED_TAGS', 'femaleBreast,topless,nudity,nude,explicitNudity'))
    ))),
    'context_sensitive_support_tags' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MODERATION_CONTEXT_SENSITIVE_SUPPORT_TAGS', 'sexualContent,adultContent,pornography,nsfw'))
    ))),
    'contextual_risk_tags' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MODERATION_CONTEXTUAL_RISK_TAGS', 'pornography,nsfw,sexualContent,adultContent,weapon,violence,blood,gore'))
    ))),
    'contextual_risk_direct_block_tags' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MODERATION_CONTEXTUAL_RISK_DIRECT_BLOCK_TAGS', 'sexualContent,adultContent'))
    ))),
    'contextual_risk_direct_support_tags' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MODERATION_CONTEXTUAL_RISK_DIRECT_SUPPORT_TAGS', 'explicitNudity,nudity,nude,topless,femaleBreast'))
    ))),
    'contextual_risk_direct_weak_support_tags' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MODERATION_CONTEXTUAL_RISK_DIRECT_WEAK_SUPPORT_TAGS', 'closeup'))
    ))),
    'contextual_risk_requires_human_tags' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MODERATION_CONTEXTUAL_RISK_REQUIRES_HUMAN_TAGS', 'pornography,nsfw,weapon,violence,blood,gore'))
    ))),
    'contextual_risk_always_human_tags' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MODERATION_CONTEXTUAL_RISK_ALWAYS_HUMAN_TAGS', 'weapon,violence,blood,gore'))
    ))),
    'benign_context_tags' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MODERATION_BENIGN_CONTEXT_TAGS', 'animal,bird,fish,insect,pet,reptile,amphibian,wildlife,nature,flower,plant,orchid,snowdrop,crocus,narcissus'))
    ))),
    'human_context_tags' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MODERATION_HUMAN_CONTEXT_TAGS', 'person,people,portrait,crowd,man,woman,boy,girl,baby,child,teenager,adult,elderlyPerson,clothing'))
    ))),
];

export type TrendGoal = 'grow' | 'traffic' | 'sell' | 'authority';

export type TrendFormat = 'text' | 'image' | 'carousel' | 'video';

export type TrendTopicStatus = 'new' | 'used' | 'dismissed';

export interface TrendEvidence {
    title: string;
    url: string;
    source: string;
    published_at: string;
}

export interface TrendTopic {
    id: string;
    label: string;
    score: number;
    signal_count: number;
    outlet_count: number;
    own_posts: number;
    own_ratio: number | null;
    evidence: TrendEvidence[];
    formats: TrendFormat[];
    status: TrendTopicStatus;
    last_seen_at: string;
}

export interface TrendProfile {
    id: string;
    niche: string;
    audience: string | null;
    goal: TrendGoal;
    keywords: string[];
    excluded_keywords: string[];
    formats: TrendFormat[];
    posts_per_week: number;
    region: string;
    competitors: string[];
    source_issues: Record<string, 'permission' | 'failed'>;
    external_sources: string[];
    rss_feed_ids: string[];
    analyzed_at: string | null;
    analyzing: boolean;
}

export interface TrendNiche {
    key: string;
    keywords: string[];
    feed_category: string;
}

export interface TrendSetupFeed {
    id: string;
    title: string;
    url: string;
}

export interface TrendDirectoryEntry {
    name: string;
    url: string;
    icon_url: string | null;
    subscribed: boolean;
    feed_id: string | null;
}

export interface TrendDirectoryCategory {
    key: string;
    entries: TrendDirectoryEntry[];
}

export interface TrendSetupLimits {
    keywords: number;
    feeds: number;
}

export interface TrendExternalSource {
    key: string;
    default: boolean;
}

export interface District {
    id: string;
    name: string;
    primary_color: string;
    accent_color: string;
}

export interface School {
    id: string;
    name: string;
}

export interface User {
    id: string;
    name: string;
    email: string;
    avatar_url: string | null;
    roles: string[];
    district: District;
    school: School | null;
}

export interface Classroom {
    id: string;
    name: string;
    subject: string | null;
    grade_level: string | null;
    join_code: string;
    students_count?: number;
    students?: User[];
    spaces?: LearningSpace[];
}

/** Published listing on Discover (teacher-shared library row). */
export interface SpaceLibraryItem {
    id: string;
    space_id: string;
    title: string;
    description: string | null;
    subject: string | null;
    grade_band: string | null;
    tags: string[] | null;
    download_count: number;
    rating: number;
    rating_count: number;
    district_approved: boolean;
    published_at: string | null;
}

/** District / school student mode policy (Phase 3e). */
export interface StudentModeSettingsModel {
    id: string;
    teacher_session_enabled: boolean;
    lms_help_enabled: boolean;
    open_tutor_enabled: boolean;
    crisis_counselor_name: string | null;
    crisis_counselor_email: string | null;
    crisis_response_template: string | null;
    school?: { id: string; name: string } | null;
}

export interface LearningSpace {
    id: string;
    district_id?: string;
    /** Phase 3e: teacher_session | lms_help | open_tutor */
    student_mode?: string;
    /** When false, students do not see / cannot start multi-agent classroom for this space. */
    multi_agent_classroom_enabled?: boolean;
    title: string;
    description: string | null;
    subject: string | null;
    grade_level: string | null;
    join_code: string;
    atlaas_tone: string;
    is_published: boolean;
    is_public: boolean;
    is_archived: boolean;
    goals: string[];
    sessions_count?: number;
    classroom_id?: string | null;
    system_prompt?: string | null;
    language?: string;
    max_messages?: number | null;
    classroom?: { id: string; name: string } | null;
    teacher?: { id: string; name: string; school?: School | null };
    library_item?: SpaceLibraryItem | null;
}

/** Recent session row on teacher space detail */
export interface TeacherSpaceSessionRow {
    id: string;
    status: string;
    message_count: number;
    started_at: string;
    student?: { id: string; name: string };
}

/** Resolved image metadata from ATLAAS image providers (Wikimedia, Unsplash, Pexels). */
export interface ImageResolved {
    url: string;
    width?: number;
    height?: number;
    alt?: string;
    credit?: string;
    credit_url?: string;
    license?: string;
    source?: string;
}

export type MessageSegment =
    | { type: 'text'; content: string }
    | { type: 'image'; keyword: string; resolved?: ImageResolved | null }
    | { type: 'diagram'; diagram_type: string; description: string; svg?: string }
    | { type: 'fun_fact'; content: string }
    | { type: 'quiz'; question: string; options: string[]; answer: string };

export interface Message {
    id: string;
    role: 'user' | 'assistant' | 'teacher_inject';
    content: string;
    created_at: string;
    segments?: MessageSegment[];
}

export interface StudentSession {
    id: string;
    status: string;
    message_count: number;
    space: LearningSpace;
}

export interface SafetyAlert {
    id: string;
    severity: 'critical' | 'high' | 'medium' | 'low';
    category: string;
    alert_type?: string;
    status: 'open' | 'reviewed' | 'resolved' | 'dismissed' | 'escalated';
    created_at: string;
    student: { id: string; name: string };
    session: { space: { id: string; title: string } } | null;
    classroom_session: { lesson: { space: { id: string; title: string } } } | null;
    reviewer_notes: string | null;
}

/** Completed session row on student dashboard (with AI summary) */
export interface CompletedSessionRow {
    id: string;
    student_summary: string;
    ended_at: string;
    message_count: number;
    space: { id: string; title: string };
}

export type ToolkitFieldType = 'text' | 'textarea' | 'number' | 'select' | 'checkbox_group';

export interface ToolkitFieldSchema {
    name: string;
    label: string;
    type: ToolkitFieldType;
    required?: boolean;
    placeholder?: string;
    options?: string[];
}

export interface TeacherTool {
    id: string;
    slug: string;
    name: string;
    description: string;
    icon: string;
    category: string;
    input_schema: ToolkitFieldSchema[];
    system_prompt_template: string;
    is_built_in: boolean;
    is_active: boolean;
}

export interface ToolRun {
    id: string;
    created_at: string;
    tool: Pick<TeacherTool, 'id' | 'name' | 'icon' | 'slug'>;
}

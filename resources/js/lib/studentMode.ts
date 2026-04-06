/** Matches teacher-facing labels in SpaceController / SessionModeResolver scope. */
export function studentModeLabel(mode?: string | null): string {
    switch (mode) {
        case 'lms_help':
            return 'LMS help — your enrolled courses';
        case 'open_tutor':
            return 'Open tutor — K-12 academic topics';
        default:
            return 'Teacher session — this space only';
    }
}

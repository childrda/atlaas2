<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; max-width: 600px; margin: 40px auto; color: #333;">
    <h2 style="color: #1E3A5F;">ATLAAS Safety Alert</h2>

    <p>A safety concern was detected in one of your Learning Spaces.</p>

    @php
        $spaceTitle = $alert->session?->space?->title
            ?? $alert->classroomSession?->lesson?->space?->title
            ?? 'Classroom lesson';
    @endphp

    <table style="border-collapse: collapse; width: 100%;">
        <tr>
            <td style="padding: 8px; font-weight: bold; width: 140px;">Student</td>
            <td style="padding: 8px;">{{ $alert->student->name }}</td>
        </tr>
        <tr style="background: #f9f9f9;">
            <td style="padding: 8px; font-weight: bold;">Space</td>
            <td style="padding: 8px;">{{ $spaceTitle }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; font-weight: bold;">Category</td>
            <td style="padding: 8px;">{{ ucfirst(str_replace('_', ' ', $alert->category)) }}</td>
        </tr>
        <tr style="background: #f9f9f9;">
            <td style="padding: 8px; font-weight: bold;">Severity</td>
            <td style="padding: 8px; color: #c0392b; font-weight: bold;">{{ strtoupper($alert->severity) }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; font-weight: bold;">Time</td>
            <td style="padding: 8px;">{{ $alert->created_at->format('M j, Y g:i A') }}</td>
        </tr>
    </table>

    <p style="margin-top: 24px;">
        <a href="{{ config('app.url') }}/teach/alerts"
           style="background: #1E3A5F; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px;">
            Review this alert in ATLAAS
        </a>
    </p>

    <p style="margin-top: 24px; color: #666; font-size: 13px;">
        If this student may be in immediate danger, contact your school administration
        or emergency services directly. Do not rely solely on this system.
    </p>
</body>
</html>

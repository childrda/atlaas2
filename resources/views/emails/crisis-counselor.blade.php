<p>A student crisis signal was detected in ATLAAS.</p>
<p><strong>Student:</strong> {{ $alert->student->name }}</p>
<p><strong>Category:</strong> {{ $alert->category }}</p>
<p><strong>Severity:</strong> {{ $alert->severity }}</p>
<p><strong>Counselor contact (configured):</strong> {{ $settings->crisis_counselor_name ?? '—' }}</p>
<p>Please review the alert in the teacher safety queue. Trigger content is stored encrypted in the application.</p>

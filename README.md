EduVault — Academic Submission & AI/Plagiarism Detection Platform
EduVault is a full-stack web application built for academic environments that need a secure, organized system for assignment submission and integrity checking. Developed using HTML, CSS, JavaScript, PHP, and MySQL and deployable on XAMPP, it provides both students and administrators with a clean, role-based interface to manage coursework submissions while automatically flagging duplicate or AI-generated content.
Key Features

Secure Authentication — Role-based login system with separate flows for students and admins, including a 3-step registration process and session management.
Assignment Submission — Students can upload assignments (PDF/DOCX) tied to specific courses. Each upload is stored securely with a unique hashed filename.
Similarity Detection Engine — A custom-built PHP and JavaScript similarity analyzer compares every new submission against existing ones, calculating match percentages and highlighting overlapping text snippets.
AI Content Detection — A multi-signal AI detection engine (ai_detect.php + ai_detection.js) runs 8 linguistic analysis checks on submitted text to estimate the likelihood of AI-generated content — all built from scratch without any external API.
Admin Dashboard — Admins can view all submissions, manage users and courses, inspect similarity matches with side-by-side comparison, and track system activity through a full audit log.
Activity Logging — Every login, upload, and admin action is recorded in a dedicated activity_log table for accountability.
Database-Driven — Six normalized MySQL tables handle users, submissions, similarity matches, courses, activity logs, and system settings.

Tech Stack
HTML · CSS · JavaScript · PHP  · MySQL · XAMPP · PDO

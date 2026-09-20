# PC to STB Marketplace Token Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Securely push active marketplace tokens from PC to STB worker.
**Architecture:** Add STB-side authenticated import endpoint and PC-side push command/service.
**Tech Stack:** Laravel 11, PHP 8.3, PHPUnit.

## Tasks
- [ ] Task 1: Write failing feature tests for STB token import endpoint and PC token push service.
- [ ] Task 2: Implement STB token import endpoint and service logic.
- [ ] Task 3: Implement PC pushToStb service method, Artisan command, and config.
- [ ] Task 4: Run full test suite, verify clean credentials sanitization, and prepare STB sync guide.

# Accessibility Checklist - JERSIC.ID WMS

## 1. Keyboard Navigation
- [x] All interactive elements are focusable
- [x] Focus indicators are visible (focus:border-cyan-400)
- [x] Skip to main content link added
- [ ] Tab order is logical
- [ ] No keyboard traps

## 2. Color & Contrast
- [x] Text contrast ratio ≥ 4.5:1 (white on slate-900)
- [ ] Link color is distinguishable from body text
- [ ] Color is not the only visual indicator
- [ ] Focus states are visible

## 3. Forms
- [x] All inputs have associated labels
- [x] Error messages are clear and descriptive
- [x] Required fields are indicated
- [ ] Form validation happens on submit (not on blur)
- [ ] Error messages are announced to screen readers

## 4. Images
- [x] All images have alt text
- [x] Decorative images have alt=""
- [ ] Image dimensions are specified (width/height)
- [ ] Images are optimized (WebP format)

## 5. Animations
- [x] No non-composited animations (removed focus:ring)
- [ ] Animations can be paused/stopped
- [ ] No flashing content (>3 flashes/second)

## 6. ARIA & Semantic HTML
- [ ] ARIA labels on icon buttons
- [ ] ARIA roles where needed
- [ ] Semantic HTML (nav, main, header, footer)
- [ ] Heading hierarchy is logical (h1 → h2 → h3)

## 7. Mobile & Responsive
- [x] Touch targets ≥ 44x44px
- [x] No horizontal scrolling
- [x] Text can be resized up to 200%
- [ ] Orientation change is supported

## 8. Screen Readers
- [ ] Live regions for dynamic content
- [ ] Status messages are announced
- [ ] Custom widgets have ARIA roles
- [ ] Focus management on modals

## 9. Testing Tools
- [ ] axe-core automated tests
- [ ] Lighthouse accessibility audit
- [ ] Manual keyboard testing
- [ ] Screen reader testing (NVDA, VoiceOver)

## 10. Documentation
- [ ] Accessibility statement published
- [ ] Known issues documented
- [ ] Contact info for accessibility feedback
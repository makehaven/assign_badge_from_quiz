--------------------------------------------------
Assign Badge From Quiz Module for MakeHaven
--------------------------------------------------

Version: 1.0
Date: August 11, 2025
Drupal Core Requirement: ^10
Dependencies: Quiz, Views, Taxonomy, Node

## DESCRIPTION

This module provides a seamless integration between Drupal's Quiz module and a custom badging system. When a member successfully completes a quiz with a perfect score, this module automatically handles the process of assigning the corresponding badge.

It enhances the user experience by providing immediate and clear feedback on the quiz results page, including next steps for badges that require a practical checkout with a facilitator.

## FEATURES

* **Automatic Badge Assignment**: Creates a "badge_request" node upon 100% quiz completion.
* **Dynamic Status**: Sets the badge request status to "active" if no checkout is needed, or "pending" if a practical checkout is required.
* **Prerequisite Gate Enforcement**: If a badge has prerequisite badges and/or a training documentation webform configured, badge requests are blocked until all configured gates pass.
* **Smart Post-Quiz Display**: Automatically injects a "next steps" area into the main content of the quiz results page, compatible with any theme (including admin themes like Gin).
* **Conditional Information**:
    * If a checkout is required, it displays the estimated time, a link to the checkout checklist, and a filtered list of available facilitators for that specific badge.
    * If no facilitators are scheduled, it shows a configurable, user-friendly message.
    * If no checkout is required, it confirms that the badge has been earned.
* **Actionable Buttons**: Provides clear buttons for the user to return to the badge's main page or view the site-wide equipment list.

## REQUIREMENTS

This module requires the following Drupal modules to be installed and enabled:
* Quiz
* Views
* Taxonomy
* Node

It also depends on a "Badges" taxonomy vocabulary with the following fields:
* `field_badge_quiz_reference` (Entity Reference to the Quiz)
* `field_badge_checkout_requirement` (List (text): 'yes', 'no', or 'class')
* `field_badge_checklist` (Link)
* `field_badge_checkout_minutes` (Number, Integer)

Finally, it requires a View named `facilitator_schedules` with a display named `facilitator_schedule_tool_eva` that can be filtered by a taxonomy term ID (the badge's ID) to show available facilitators.

## INSTALLATION

1.  Place the `assign_badge_from_quiz` module folder within your Drupal site's `modules/custom` directory.
2.  Navigate to the **Extend** page (`/admin/extend`) in your Drupal admin UI.
3.  Find "Assign Badge From Quiz" in the list and check the box to enable it.
4.  Click **Install**.
5.  Clear all Drupal caches.

## USAGE - HOW TO LINK A QUIZ TO A BADGE

This module does **not** require a special type of quiz. To make the system work for any quiz, you must link it from its corresponding badge term.

1.  Navigate to your list of badges at `/admin/structure/taxonomy/manage/badges`.
2.  Click **Edit** for the badge you want to associate with a quiz (e.g., "Laser Cutter").
3.  Find the **"Badge Quiz Reference"** field.
4.  Begin typing the name of the quiz and select it from the autocomplete list.
5.  **Save** the badge term.

Once this reference is saved, any user who scores 100% on that quiz will trigger the badge assignment process and see the custom results page.

## HOW IT WORKS

The module's functionality is split into two main parts:

1.  **Backend Logic (`.module` file)**:
    * When a `quiz_result` entity is saved (i.e., a quiz is completed), the `assign_badge_from_quiz_entity_update()` hook is triggered.
    * It checks if the score is 100%.
    * If so, it finds the corresponding badge term by matching the quiz ID from the badge's `field_badge_quiz_reference`.
    * It then creates a new `badge_request` node with the appropriate status ('pending' or 'active') for the user.

2.  **Frontend Display (Event Subscriber & Service)**:
    * The `QuizResultPageSubscriber` listens for when a quiz result page is about to be displayed.
    * It retrieves the `quiz_result` from the page's route.
    * It passes this result to the `QuizResultDisplayBuilder` service.
    * The service builds a comprehensive render array based on the quiz score and the linked badge's requirements (e.g., fetching facilitator schedules).
    * The subscriber then injects this entire display into the main content of the page, ensuring it works across all themes without manual block placement.
```

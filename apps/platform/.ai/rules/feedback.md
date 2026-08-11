---
paths:
  - 'resources/js/components/feedback/**'
---

# Feedback

## Controlled alert dialogs skip DialogTrigger
When ConfirmDestroyDialog (or any RAC alert) is opened via external open/onOpenChange with no pressable trigger (e.g. from a menu item), render AlertDialogContent with isOpen/onOpenChange directly. Do not wrap in AlertDialogTrigger — DialogTrigger always attaches PressResponder to its first child and warns if that child is not pressable. Use AlertDialogTrigger only when a real trigger Button is present.

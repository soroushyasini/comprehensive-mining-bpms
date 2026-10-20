# Procurement follow-up now runs inside the module

Do not install a ProcessMaker process, native form or procurement trigger.
The main procurement panel and authenticated EMCORE API implement all decisions.

See [the current deployment guide](../../docs/PROCUREMENT_WORKFLOW.md).
The retired process definition, triggers and native panel remain recoverable in
Git history before the in-panel workflow migration. Existing real case links
are not cleared or converted automatically.

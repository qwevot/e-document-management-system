# NEC E-Document Management System (EDMS) User Guide

## Pages & Navigation

In the sidebar, you can navigate through multiple pages in the **E-Document Management System (EDMS)** app. Here`s an **overview** of what each page contains:

| Page | Purpose | What Lives Here? |
| :--- | :--- | :--- |
| **Document Explorer** | **Main / Master Repository** | **Every active folder and file in the organization.** Use this space to browse the company directory tree, create folders, move items, and upload documents into their proper project folders. |
| **My Workspace** | **Your Personal Documents / Shared with you** | A personal lens showing **only documents you uploaded or contributed to**, filterable by status (`Active`, `Hold`, `Rejected`, `Published`, `Suspend`, `Obsolete`). |
| Starred | Documents you starred | Documents and folders you have bookmarked with a star (`★`) for fast access |
| Recently Opened | Documents you recently opened | A chronological history of documents you recently viewed. |
| **Pending Review** | **Documents that needs your attention/review** | Documents currently waiting for **your** approval decision in a review workflow. |

> **Tip:** Need to find a document across the entire company? Start in **Document Explorer** or use the search bar at the top of the table.

---

## Document Lifecycle

Every document in EDMS carries a color-coded status badge that controls how it can be edited, reviewed, or shared:

| Status | Meaning | What Happens in This State? |
| :--- | :--- | :--- |
| <span class="status-badge badge-active">Active</span> | **Working Draft** | Default state when a file is uploaded. Contributors can upload new versions, rename, move, or submit the document for review. |
| <span class="status-badge badge-hold">Hold</span> | **Under Review** | The document is currently in a **Sequential Review** queue. While on `Hold`, file edits and new version uploads are locked until reviewers finish. |
| <span class="status-badge badge-publish">Published</span> | **Approved & Official** | All assigned reviewers have approved the document (`v1.0`, `v2.0`, etc.). While `Published`, the official release is locked from edits or new reviews until it is **Suspended**. |
| <span class="status-badge badge-rejected">Rejected</span> | **Revision Needed** | A reviewer rejected the submission with feedback. Upload a revised version and submit a new review request when ready. |
| <span class="status-badge badge-suspend">Suspend</span> | **Reopened for Major Revision** | Unlocked from `Published` so contributors can prepare the next major release (e.g., `v1.0 → v1.1 → v2.0`). Uploading a new revision transitions it back to `Active`. |
| <span class="status-badge badge-obsolete">Obsolete</span> | **In Trash** | Soft-deleted from active folders. Can be restored from **My Workspace → Obsolete (Trash)** or permanently purged/archived by an Admin. |

---

## Reviews & Markup

### 1. Submitting a Document for Sequential Review
1. Open any document to enter the **Document Viewer**.
2. Click **Request Review** in the right-hand workflow panel.
3. Add one or more **Review Stages** (`Stage 1`, `Stage 2`, etc.) and assign reviewers to each stage.
4. Once submitted, the document status changes to **Hold** and **Stage 1** reviewers receive a notification bell alert.
5. Each stage unlocks automatically in sequence once the previous stage is approved. If **all** stages approve, the document automatically becomes **Published**.

### 2. PDF Annotation Layers & Comments
Inside the **Document Viewer**, reviewers and collaborators can mark up documents without altering the original uploaded file:
- **Drawing & Text Tools**: Use the top viewer toolbar (**Pen**, **Highlighter**, **Text Box**, **Eraser**) to draw directly over any PDF or Office document (Word, Excel, and PowerPoint files are automatically converted to PDF previews).
- **Annotation Layers**: Group your markups into named layers in the right sidebar. You can toggle layers on/off with the eye icon or export an **Annotated PDF** with visible layers flattened onto the pages.
- **Version History**: Upload updated revisions under the **Versions** tab while preserving every prior version for audit traceability.

---

## Roles & Permissions

Your account role determines which actions are available across folders, documents, and administrative menus:

| Capability | Super Admin / Admin | Engineer | Technician | Viewer |
| :--- | :---: | :---: | :---: | :---: |
| View, preview & download documents | Yes | Yes | Yes | Yes |
| Star items & use AI Assistant | Yes | Yes | Yes | Yes |
| Upload files, create folders & upload new versions | Yes | Yes | Yes | — |
| Submit documents for review & draw annotations | Yes | Yes | Yes | — |
| Approve / Reject assigned reviews | Yes | Yes | Yes *(if assigned)* | — |

### Need Further Assistance?
- Click the **AI Assistant** button in the bottom-right corner of the screen to ask questions in natural language (or by voice).
- Contact your system administrator if you need a role upgrade or a deleted file restored from **Archives**.

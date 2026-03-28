# Pipeline Builder Interface

React-based interface for creating and managing Pipelines and Flows, backed by the `/wp-json/datamachine/v1/` REST API.

## Architecture

**TanStack Query** manages server state (pipelines, flows, handler metadata, chat sessions/messages) with caching + invalidation.

**Zustand UI store** (`inc/Core/Admin/Pages/Pipelines/assets/react/stores/uiStore.js`) manages client UI state and persists a small subset to `localStorage`:
- `selectedPipelineId`
- `isChatOpen`
- `chatSessionId`

## Pipeline Management

- **Pipeline selection** is stored as `selectedPipelineId` in the Zustand UI store.
- **Pipeline CRUD** uses REST endpoints via `inc/Core/Admin/Pages/Pipelines/assets/react/utils/api.js` (wrapper around `@wordpress/api-fetch` + nonce).

## Flow Management

Flows are created under a pipeline and displayed with pagination.

## Step Configuration

Step and handler configuration is driven by React modals and handler schemas from the REST API.

- **Handler selection** uses `HandlerSelectionModal.jsx`.
- **Handler settings** uses `HandlerSettingsModal.jsx` (not a PHP template). The modal renders fields from the handler schema and submits updates via the flow update mutation.
- **OAuth connection** is surfaced through an OAuth modal (`OAuthAuthenticationModal.jsx`) and popup handler components in `inc/Core/Admin/Pages/Pipelines/assets/react/components/modals/oauth/`.

## Integrated Chat Sidebar

The Pipelines page includes a collapsible chat sidebar whose open/closed state, selected pipeline context, and active chat session are tracked in the Zustand UI store.

## REST API integration

All Pipelines page operations are REST-driven through the Pipelines page API wrapper (`inc/Core/Admin/Pages/Pipelines/assets/react/utils/api.js`), including:
- Pipelines: `GET /pipelines`, `POST /pipelines`, `PATCH /pipelines/{pipeline_id}`, `DELETE /pipelines/{pipeline_id}`
- Pipeline steps: `POST /pipelines/{pipeline_id}/steps`, `DELETE /pipelines/{pipeline_id}/steps/{step_id}`, `PUT /pipelines/{pipeline_id}/steps/reorder`
- Flows: `GET /flows?pipeline_id=...`, `POST /flows`, `GET /flows/{flow_id}`

(Endpoint surface evolves; treat this list as a starting point and confirm against `inc/Core/Admin/Pages/Pipelines/assets/react/utils/api.js` and the PHP REST controllers.)

**Performance note**: client caching and background refetching are provided by TanStack Query; UI-only state persistence is via Zustand `persist` to `localStorage`.

### Key React files

- `inc/Core/Admin/Pages/Pipelines/assets/react/PipelinesApp.jsx`
- `inc/Core/Admin/Pages/Pipelines/assets/react/components/shared/ModalManager.jsx`
- `inc/Core/Admin/Pages/Pipelines/assets/react/components/modals/HandlerSettingsModal.jsx`
- `inc/Core/Admin/Pages/Pipelines/assets/react/components/modals/OAuthAuthenticationModal.jsx`
- `inc/Core/Admin/Pages/Pipelines/assets/react/components/chat/`
- `FlowCard` - Flow instance display with scheduling and status
- `PipelineStepCard` - Individual pipeline step cards with handler info
- `FlowStepCard` - Configured flow step display with settings
- `EmptyStepCard` - Add new step interface
- `EmptyFlowCard` - Create new flow interface

**Modal Components:**

- `ConfigureStepModal` - AI configuration, system prompts, and tool selection
- `HandlerSettingsModal` - Handler-specific configuration with dynamic field rendering
- `OAuthAuthenticationModal` - OAuth provider authentication with popup handling
- `StepSelectionModal` - Step type selection interface
- `HandlerSelectionModal` - Handler selection with capability display
- `FlowScheduleModal` - Flow scheduling configuration
- `ImportExportModal` - Pipeline import/export operations with CSV handling

**Shared Components:**

- `LoadingSpinner` - Loading state visualization
- `StepTypeIcon` - Step type icons with consistent styling
- `DataFlowArrow` - Visual data flow indicators between steps
- `PipelineSelector` - Pipeline selection dropdown with preferences
- `ModalManager` - Centralized modal rendering logic (@since v0.2.3)
- `ModalSwitch` - Centralized modal routing component (@since v0.2.5)

**Specialized Sub-Components:**

- OAuth components: `ConnectionStatus`, `AccountDetails`, `APIConfigForm`, `OAuthPopupHandler`
- Files handler components: `FilesHandlerSettings`, `FileUploadInterface`, `FileStatusTable`, `AutoCleanupOption`
- Configure step components: `AIToolsSelector`, `ToolCheckbox`, `ConfigurationWarning`
- Import/export components: `ImportTab`, `ExportTab`, `CSVDropzone`, `PipelineCheckboxTable`
- File management components: `FileUploadDropzone`, `FileStatusTable`, `AutoCleanupOption`

### State Management

**Server state (TanStack Query)**

Pipelines/Flows/Handlers/Chat are fetched and cached via query hooks under `inc/Core/Admin/Pages/Pipelines/assets/react/queries/`.

**Client UI state (Zustand)**

The UI store in `inc/Core/Admin/Pages/Pipelines/assets/react/stores/uiStore.js` is intentionally small and persists only:
- `selectedPipelineId`
- `isChatOpen`
- `chatSessionId`

Modal state is also held in the UI store (`activeModal`, `modalData`) but is not persisted.

### REST API Integration

The Pipelines UI calls REST endpoints through `inc/Core/Admin/Pages/Pipelines/assets/react/utils/api.js` (a wrapper around `@wordpress/api-fetch` that reads the nonce/namespace from `window.dataMachineConfig`).

For the authoritative list of endpoints used by the UI, refer to `inc/Core/Admin/Pages/Pipelines/assets/react/utils/api.js` and the PHP REST controllers under `inc/Api/`.

### Benefits of React Architecture

**User Experience:**
- Zero page reloads for all operations
- Instant visual feedback
- Optimistic UI updates
- Real-time status updates
- Modern, responsive interface

**Developer Experience:**
- Component reusability
- Clear separation of concerns
- Testable code structure
- Maintainable state management
- Type-safe operations (via PropTypes)

**Performance:**
- Client-side caching
- Efficient re-renders via React optimization
- Lazy loading of modal content
- Reduced server load (REST API vs repeated page loads)

**Extensibility:**
- Easy to add new features
- Filter-based handler discovery
- Dynamic field rendering
- Plugin-friendly architecture

### Migration Impact

**Eliminated Code:**


**Simplified Maintenance:**
- Single responsibility components
- Declarative UI rendering
- Centralized state management
- Consistent error handling patterns

## Advanced Architecture Patterns (@since v0.2.5)

### Model-View Pattern

The Pipelines interface implements a model-view separation pattern for handler state management:

**HandlerProvider** (`inc/Core/Admin/Pages/Pipelines/assets/react/context/HandlerProvider.jsx`):
- React context providing handler state across components
- Centralizes handler selection and configuration state
- Reduces prop drilling for handler-related data

**HandlerModel** (`inc/Core/Admin/Pages/Pipelines/assets/react/models/HandlerModel.js`):
- Abstract model layer for handler data operations
- Provides consistent interface for handler state management
- Separates business logic from UI components

**HandlerFactory** (`inc/Core/Admin/Pages/Pipelines/assets/react/models/HandlerFactory.js`):
- Factory pattern for handler model instantiation
- Creates appropriate handler models based on handler type
- Centralizes handler model creation logic

**Individual Handler Models** (`inc/Core/Admin/Pages/Pipelines/assets/react/models/handlers/`):
- Type-specific handler models (e.g., TwitterHandlerModel, GoogleSheetsHandlerModel)
- Encapsulate handler-specific behavior and validation
- Provide handler-specific methods and computed properties

### Service Layer Architecture

**handlerService** (`inc/Core/Admin/Pages/Pipelines/assets/react/services/handlerService.js`):
- Service abstraction for handler-related API operations
- Separates API communication from component logic
- Provides reusable handler operation methods
- Centralizes error handling for handler operations

**Benefits**:
- Clear separation between API calls and UI logic
- Testable service layer independent of components
- Consistent error handling patterns
- Easy to mock for testing

### Modal Management System

**ModalSwitch** (`inc/Core/Admin/Pages/Pipelines/assets/react/components/shared/ModalSwitch.jsx`):
- Centralized modal rendering component
- Routes modal types to appropriate modal components
- Replaces scattered conditional modal logic
- Single source of truth for modal rendering

**Pattern**:
```javascript
// Before: Multiple conditional modal renders scattered in components
{showHandlerModal && <HandlerSettingsModal />}
{showConfigModal && <ConfigureStepModal />}
{showOAuthModal && <OAuthAuthenticationModal />}

// After: Single centralized modal switch
<ModalSwitch activeModal={activeModal} />
```

**Benefits**:
- Reduced code duplication
- Easier to add new modal types
- Centralized modal state management
- Consistent modal behavior

### Component Directory Structure

```
assets/react/
├── context/              # React context providers
│   └── HandlerProvider.jsx
├── models/               # Handler models & factory
│   ├── HandlerModel.js
│   ├── HandlerFactory.js
│   └── handlers/         # Type-specific models
├── services/             # API service layer
│   └── handlerService.js
├── hooks/                # Custom React hooks
│   ├── useHandlerModel.js
│   └── useFormState.js
├── queries/              # TanStack Query definitions
│   ├── flows.js
│   └── handlers.js
├── stores/               # Zustand stores
│   └── modalStore.js
└── components/           # React components
    ├── modals/
    ├── flows/
    ├── pipelines/
    └── shared/
```

### Pattern Benefits

**Model-View Separation**:
- Business logic isolated from UI rendering
- Easier testing of handler operations
- Reusable handler logic across components

**Service Layer**:
- API calls abstracted from components
- Consistent error handling patterns
- Easy to switch API implementations

**Centralized Modal Management**:
- Single modal rendering location
- Reduced conditional logic in components
- Easier modal state debugging

**Custom Hooks**:
- Reusable state management logic
- Consistent data fetching patterns
- Simplified component logic

**Implemented Features:**
React architecture provides modern features including:
- Drag-and-drop step reordering (implemented)
- Real-time collaboration (possible)
- Advanced validation UI (easy to implement)
- Undo/redo functionality (state-based)
- Keyboard shortcuts (event-based)
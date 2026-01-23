# Frontend

React + TypeScript + Vite frontend application connected to the Laravel API backend.

## Features

- **React 18** with TypeScript
- **Vite** for fast development and building
- **React Router** for client-side routing
- **Axios** for API communication
- **Ziggy** for Laravel route integration

## Requirements

- Node.js 20+
- npm or pnpm

## Installation

```bash
# Install dependencies
npm install

# Copy environment file
cp .env.example .env
```

## Configuration

Create a `.env` file with:

```env
VITE_API_URL=http://localhost:8000/api/v1
```

## Development

```bash
# Start development server
npm run dev
```

The development server will start at `http://localhost:5173`.

## Building

```bash
# Build for production
npm run build

# Preview production build
npm run preview
```

## Linting

```bash
# Run ESLint
npm run lint
```

## Project Structure

```
src/
├── api/          # API client and endpoints
├── components/   # Reusable UI components
├── contexts/     # React contexts
├── hooks/        # Custom React hooks
├── pages/        # Page components
├── types/        # TypeScript type definitions
├── App.tsx       # Main application component
└── main.tsx      # Application entry point
```

## API Integration

The frontend is pre-configured to connect to the Laravel backend API. The `auth_token` is stored in `localStorage` and automatically included in API requests.

### Available Pages

- `/` - Home page (Dashboard when authenticated)
- `/login` - User login
- `/register` - User registration

## Expanding the ESLint configuration

If you are developing a production application, we recommend updating the configuration to enable type-aware lint rules:

```js
export default defineConfig([
  globalIgnores(['dist']),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      // Other configs...

      // Remove tseslint.configs.recommended and replace with this
      tseslint.configs.recommendedTypeChecked,
      // Alternatively, use this for stricter rules
      tseslint.configs.strictTypeChecked,
      // Optionally, add this for stylistic rules
      tseslint.configs.stylisticTypeChecked,

      // Other configs...
    ],
    languageOptions: {
      parserOptions: {
        project: ['./tsconfig.node.json', './tsconfig.app.json'],
        tsconfigRootDir: import.meta.dirname,
      },
      // other options...
    },
  },
])
```

You can also install [eslint-plugin-react-x](https://github.com/Rel1cx/eslint-react/tree/main/packages/plugins/eslint-plugin-react-x) and [eslint-plugin-react-dom](https://github.com/Rel1cx/eslint-react/tree/main/packages/plugins/eslint-plugin-react-dom) for React-specific lint rules:

```js
// eslint.config.js
import reactX from 'eslint-plugin-react-x'
import reactDom from 'eslint-plugin-react-dom'

export default defineConfig([
  globalIgnores(['dist']),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      // Other configs...
      // Enable lint rules for React
      reactX.configs['recommended-typescript'],
      // Enable lint rules for React DOM
      reactDom.configs.recommended,
    ],
    languageOptions: {
      parserOptions: {
        project: ['./tsconfig.node.json', './tsconfig.app.json'],
        tsconfigRootDir: import.meta.dirname,
      },
      // other options...
    },
  },
])
```

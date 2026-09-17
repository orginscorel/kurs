import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { UpdateApp } from './UpdateApp'
import './styles.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <UpdateApp />
  </StrictMode>,
)

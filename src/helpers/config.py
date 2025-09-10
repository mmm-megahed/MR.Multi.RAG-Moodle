from pydantic_settings import BaseSettings, SettingsConfigDict
from typing import List

class Settings(BaseSettings):

    APP_NAME: str
    APP_VERSION: str

    FILE_ALLOWED_TYPES: list
    FILE_MAX_SIZE: int
    FILE_DEFAULT_CHUNK_SIZE: int

    POSTGRES_USERNAME: str
    POSTGRES_PASSWORD: str
    POSTGRES_HOST: str
    POSTGRES_PORT: int
    POSTGRES_MAIN_DATABASE: str

    GENERATION_BACKEND: str
    EMBEDDING_BACKEND: str

    # OpenAI Configuration
    OPENAI_API_KEY: str = None
    OPENAI_API_URL: str = None
    
    # Cohere Configuration
    COHERE_API_KEY: str = None
    
    # Ollama Configuration
    OLLAMA_API_URL: str = "http://localhost:11434"

    # Model Configuration
    GENERATION_MODEL_ID_LITERAL: List[str] = None
    GENERATION_MODEL_ID: str = None
    
    # Embedding Model Configuration (backend-specific)
    EMBEDDING_MODEL_ID: str = None
    EMBEDDING_MODEL_SIZE: int = None
    
    # Cohere-specific embedding settings
    COHERE_EMBEDDING_MODEL_ID: str = None
    COHERE_EMBEDDING_MODEL_SIZE: int = None
    
    # Ollama-specific embedding settings
    OLLAMA_EMBEDDING_MODEL_ID: str = "nomic-embed-text"
    OLLAMA_EMBEDDING_MODEL_SIZE: int = 768

    # Generation Settings
    INPUT_DAFAULT_MAX_CHARACTERS: int = None
    GENERATION_DAFAULT_MAX_TOKENS: int = None
    GENERATION_DAFAULT_TEMPERATURE: float = None

    # Vector DB Configuration
    VECTOR_DB_BACKEND_LITERAL: List[str] = None
    VECTOR_DB_BACKEND: str
    VECTOR_DB_PATH: str
    VECTOR_DB_DISTANCE_METHOD: str = None
    VECTOR_DB_PGVEC_INDEX_THRESHOLD: int = 100

    # Language Settings
    PRIMARY_LANG: str = "en"
    DEFAULT_LANG: str = "en"

    class Config:
        env_file = ".env"
    
    @property
    def get_embedding_model_id(self) -> str:
        """Get the appropriate embedding model ID based on the backend"""
        if self.EMBEDDING_BACKEND.upper() == "COHERE":
            return self.COHERE_EMBEDDING_MODEL_ID or self.EMBEDDING_MODEL_ID
        elif self.EMBEDDING_BACKEND.upper() == "OLLAMA":
            return self.OLLAMA_EMBEDDING_MODEL_ID or self.EMBEDDING_MODEL_ID
        else:
            return self.EMBEDDING_MODEL_ID
    
    @property
    def get_embedding_model_size(self) -> int:
        """Get the appropriate embedding model size based on the backend"""
        if self.EMBEDDING_BACKEND.upper() == "COHERE":
            return self.COHERE_EMBEDDING_MODEL_SIZE or self.EMBEDDING_MODEL_SIZE
        elif self.EMBEDDING_BACKEND.upper() == "OLLAMA":
            return self.OLLAMA_EMBEDDING_MODEL_SIZE or self.EMBEDDING_MODEL_SIZE
        else:
            return self.EMBEDDING_MODEL_SIZE

def get_settings():
    return Settings()
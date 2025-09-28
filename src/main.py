from fastapi import FastAPI
from routes import base, data, nlp
from helpers.config import get_settings
from stores.llm.LLMProviderFactory import LLMProviderFactory
from stores.vectordb.VectorDBProviderFactory import VectorDBProviderFactory
from stores.llm.templates.template_parser import TemplateParser
from sqlalchemy.ext.asyncio import create_async_engine, AsyncSession
from sqlalchemy.orm import sessionmaker
import logging

# Import metrics setup
from utils.metrics import setup_metrics

from routes.video_processor import router as video_router

app = FastAPI()

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Setup Prometheus metrics
setup_metrics(app)

async def startup_span():
    try:
        settings = get_settings()
        logger.info("Starting application initialization...")

        postgres_conn = f"postgresql+asyncpg://{settings.POSTGRES_USERNAME}:{settings.POSTGRES_PASSWORD}@{settings.POSTGRES_HOST}:{settings.POSTGRES_PORT}/{settings.POSTGRES_MAIN_DATABASE}"

        app.db_engine = create_async_engine(postgres_conn)
        app.db_client = sessionmaker(
            app.db_engine, class_=AsyncSession, expire_on_commit=False
        )
        logger.info("Database connection initialized")

        llm_provider_factory = LLMProviderFactory(settings)
        vectordb_provider_factory = VectorDBProviderFactory(config=settings, db_client=app.db_client)

        # Generation client setup
        logger.info(f"Initializing generation client with backend: {settings.GENERATION_BACKEND}")
        app.generation_client = llm_provider_factory.create(provider=settings.GENERATION_BACKEND)
        
        if app.generation_client is None:
            raise ValueError(f"Failed to create generation client for backend: {settings.GENERATION_BACKEND}")
            
        app.generation_client.set_generation_model(model_id=settings.GENERATION_MODEL_ID)
        logger.info(f"Generation model set: {settings.GENERATION_MODEL_ID}")

        # Embedding client setup - use the property methods to get correct configuration
        logger.info(f"Initializing embedding client with backend: {settings.EMBEDDING_BACKEND}")
        app.embedding_client = llm_provider_factory.create(provider=settings.EMBEDDING_BACKEND)
        
        if app.embedding_client is None:
            raise ValueError(f"Failed to create embedding client for backend: {settings.EMBEDDING_BACKEND}")
            
        embedding_model_id = settings.get_embedding_model_id
        embedding_model_size = settings.get_embedding_model_size
        
        app.embedding_client.set_embedding_model(
            model_id=embedding_model_id,
            embedding_size=embedding_model_size
        )
        logger.info(f"Embedding model set: {embedding_model_id} (size: {embedding_model_size})")
        
        # Vector DB client setup
        logger.info(f"Initializing vector database with backend: {settings.VECTOR_DB_BACKEND}")
        app.vectordb_client = vectordb_provider_factory.create(
            provider=settings.VECTOR_DB_BACKEND
        )
        await app.vectordb_client.connect()
        logger.info("Vector database connected")

        app.template_parser = TemplateParser(
            language=settings.PRIMARY_LANG,
            default_language=settings.DEFAULT_LANG,
        )
        logger.info("Template parser initialized")

        # Test connectivity (optional but helpful for debugging)
        await test_providers_connectivity()
        
        logger.info("Application initialization completed successfully!")

    except Exception as e:
        logger.error(f"Failed to initialize application: {e}")
        raise

async def test_providers_connectivity():
    """Test if providers are working correctly"""
    try:
        # Test embedding client
        logger.info("Testing embedding client connectivity...")
        test_embeddings = app.embedding_client.embed_text("test connectivity")
        if test_embeddings:
            logger.info(f"Embedding test successful - got {len(test_embeddings[0])} dimensions")
        else:
            logger.warning("Embedding test returned None")
            
        # Test generation client
        logger.info("Testing generation client connectivity...")
        test_generation = app.generation_client.generate_text("Say 'hello'", max_output_tokens=10)
        if test_generation:
            logger.info(f"Generation test successful - response: {test_generation[:50]}...")
        else:
            logger.warning("Generation test returned None")
            
    except Exception as e:
        logger.warning(f"Provider connectivity test failed: {e}")
        # Don't raise here as the app might still work, just log the warning

async def shutdown_span():
    try:
        logger.info("Shutting down application...")
        
        if hasattr(app, 'db_engine'):
            await app.db_engine.dispose()
            logger.info("Database connection closed")
            
        if hasattr(app, 'vectordb_client'):
            await app.vectordb_client.disconnect()
            logger.info("Vector database disconnected")
            
        logger.info("Application shutdown completed")
        
    except Exception as e:
        logger.error(f"Error during shutdown: {e}")

app.on_event("startup")(startup_span)
app.on_event("shutdown")(shutdown_span)

app.include_router(base.base_router)
app.include_router(data.data_router)
app.include_router(nlp.nlp_router)
app.include_router(video_router)

@app.get("/welcome") #test endpoint
def welcome():
    return {"message": "Welcome to the multi-RAG API!"}

@app.get("/health") #health check endpoint
async def health_check():
    """Health check endpoint to verify all services are running"""
    try:
        health_status = {
            "status": "healthy",
            "services": {
                "database": "connected" if hasattr(app, 'db_engine') else "disconnected",
                "vector_db": "connected" if hasattr(app, 'vectordb_client') else "disconnected",
                "generation_client": "initialized" if hasattr(app, 'generation_client') else "not_initialized",
                "embedding_client": "initialized" if hasattr(app, 'embedding_client') else "not_initialized",
            }
        }
        
        # Get current settings for debugging
        settings = get_settings()
        health_status["configuration"] = {
            "generation_backend": settings.GENERATION_BACKEND,
            "embedding_backend": settings.EMBEDDING_BACKEND,
            "vector_db_backend": settings.VECTOR_DB_BACKEND,
            "generation_model": settings.GENERATION_MODEL_ID,
            "embedding_model": settings.EMBEDDING_MODEL_ID,
        }
        
        return health_status
        
    except Exception as e:
        logger.error(f"Health check failed: {e}")
        return {
            "status": "unhealthy",
            "error": str(e)
        }
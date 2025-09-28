# Moodle Setup Instructions

This project requires Moodle and Moodle Docker setup. The `docker/moodle/` and `docker/moodle-docker/` directories are not included in this repository to keep it lightweight.

## Option 1: Download from Official Repositories (Recommended)

```bash
cd docker
git clone https://github.com/moodle/moodle.git
git clone https://github.com/moodlehq/moodle-docker.git
```

## Option 2: Download from Moodle.org

1. Go to https://download.moodle.org/
2. Download the latest stable version
3. Extract it to `docker/moodle/`

## Option 3: Use Docker Images

You can also use the official Moodle Docker images by updating your `docker-compose.yml` to use:
```yaml
moodle:
  image: moodle/moodle:latest
  # ... rest of your configuration
```

## After Downloading

Once you have both directories in place, you can proceed with the Docker setup:

```bash
cd docker
sudo docker compose up -d
```

## Why This Approach?

- Keeps the repository lightweight
- Allows users to choose their preferred Moodle version
- Prevents conflicts with different Moodle installations
- Follows best practices for including third-party software
- Reduces repository size significantly
